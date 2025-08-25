<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\BabelHasher;

/**
 * String-backed write-side: index-driven (no runtime attribute parsing).
 *
 * - onFlush: for each entity with translatables, compute (src|ctx|original) → hash and enqueue:
 *      * STR upsert
 *      * TR ensure rows for all enabled locales (text = NULL)
 *      * optional immediate texts via $_pendingTranslations[field][locale] = text
 * - postFlush: DBAL upserts with DBAL 4 platform detection.
 *
 * Conventions:
 *   - Entities expose getBackingValue(string $field): ?string (hook API).
 *   - TranslatableIndex provides: fieldsFor(FQCN), configFor(FQCN), localeAccessorFor(FQCN).
 *   - LocaleContext: ->getDefault(), ->getEnabled().
 *   - Hashing: BabelHasher::forString(src, ctx, original).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    /** @var array<string, array{original:string, src:string}> keyed by hash */
    private array $pending = [];

    /** @var array<int, array{hash:string, locale:string, text:string}> */
    private array $pendingWithText = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending = [];
        $this->pendingWithText = [];

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $collected = 0;
        foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $entity) {
            $collected += $this->collectFromEntity($entity, 'onFlush');
        }

        $this->logger->info('Babel onFlush collected', [
            'entities' => $collected,
            'pending'  => \count($this->pending),
            'texts'    => \count($this->pendingWithText),
        ]);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === [] && $this->pendingWithText === []) {
            return;
        }

        $em       = $args->getObjectManager();
        $conn     = $em->getConnection();
        $platform = $conn->getDatabasePlatform();

        $now = $platform instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';
        $strTable = 'str';
        $trTable  = 'str_translation';

        // STR upsert
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$now}, {$now})
                       ON CONFLICT (hash) DO UPDATE
                         SET original = EXCLUDED.original,
                             src_locale = EXCLUDED.src_locale,
                             updated_at = {$now}";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$now}, {$now})
                       ON CONFLICT(hash) DO UPDATE SET
                         original = excluded.original,
                         src_locale = excluded.src_locale,
                         updated_at = {$now}";
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$now}, {$now})
                       ON DUPLICATE KEY UPDATE
                         original = VALUES(original),
                         src_locale = VALUES(src_locale),
                         updated_at = {$now}";
        } else {
            $this->logger->error('Babel postFlush: unsupported DB platform', ['platform' => $platform::class]);
            return;
        }

        // TR ensure (nullable text => NULL)
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$now}, {$now})
                            ON CONFLICT (hash, locale) DO NOTHING";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$now}, {$now})
                            ON CONFLICT(hash, locale) DO NOTHING";
        } else {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$now}, {$now})
                            ON DUPLICATE KEY UPDATE text = text";
        }

        // TR upsert translated text
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$now}, {$now})
                            ON CONFLICT (hash, locale) DO UPDATE
                              SET text = EXCLUDED.text, updated_at = {$now}";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$now}, {$now})
                            ON CONFLICT(hash, locale) DO UPDATE SET
                              text = excluded.text, updated_at = {$now}";
        } else {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$now}, {$now})
                            ON DUPLICATE KEY UPDATE
                              text = VALUES(text), updated_at = {$now}";
        }

        $locales = $this->locale->getEnabled() ?: [$this->locale->getDefault()];
        \assert($locales !== [], 'Babel: enabled locales must not be empty');

        $started = false;
        try {
            if (!$conn->isTransactionActive()) {
                $conn->beginTransaction();
                $started = true;
            }

            foreach ($this->pending as $hash => $row) {
                $conn->executeStatement($sqlStr, [
                    'hash'     => $hash,
                    'original' => $row['original'],
                    'src'      => $row['src'],
                ]);
                foreach ($locales as $loc) {
                    $conn->executeStatement($sqlTrEnsure, [
                        'hash'   => $hash,
                        'locale' => (string)$loc,
                    ]);
                }
            }

            foreach ($this->pendingWithText as $r) {
                $conn->executeStatement($sqlTrUpsert, $r);
            }

            if ($started) {
                $conn->commit();
            }
            $this->logger->info('Babel postFlush finished', [
                'str'      => \count($this->pending),
                'tr_texts' => \count($this->pendingWithText),
            ]);
        } catch (\Throwable $e) {
            if ($started && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('Babel postFlush failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        } finally {
            $this->pending = [];
            $this->pendingWithText = [];
        }
    }

    /**
     * Per-entity collector using the hook API: getBackingValue($field).
     * Hash inputs: srcLocale (BabelLocale or default), context (from index), original (backing value).
     */
    private function collectFromEntity(object $entity, string $phase): int
    {
        $class  = $entity::class;
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) return 0;

        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel collect: entity missing hooks API; skipping.', ['class'=>$class,'phase'=>$phase]);
            return 0;
        }

        $srcLocale = $this->resolveSourceLocale($entity, $class);
        \assert($srcLocale !== '', 'Babel: srcLocale must not be empty during hashing');

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        $count = 0;
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }
            $context = $fieldCfg[$field]['context'] ?? null;
            $hash    = BabelHasher::forString($srcLocale, $context, $original);

            // trace while stabilizing
            $this->logger->debug('babel.hash', [
                'phase' => $phase,
                'class' => $class,
                'field' => $field,
                'src'   => $srcLocale,
                'ctx'   => $context,
                'hash'  => $hash,
            ]);

            $this->pending[$hash] = [
                'original' => $original,
                'src'      => $srcLocale,
            ];

            if (\property_exists($entity, '_pendingTranslations') && \is_array($entity->_pendingTranslations ?? null)) {
                $pairs = $entity->_pendingTranslations[$field] ?? null;
                if (\is_array($pairs)) {
                    foreach ($pairs as $loc => $txt) {
                        if (!\is_string($loc) || !\is_string($txt) || $txt === '') continue;
                        $this->pendingWithText[] = [
                            'hash'   => $hash,
                            'locale' => $loc,
                            'text'   => $txt,
                        ];
                    }
                }
            }

            $count++;
        }

        return $count;
    }

    /**
     * Resolve source locale for hashing:
     *  - If TranslatableIndex defines a BabelLocale accessor (prop/method), use it.
     *  - Else fallback to the application's DEFAULT locale (NOT the current request).
     */
    private function resolveSourceLocale(object $entity, string $class): string
    {
        $acc = $this->index->localeAccessorFor($class);
        if ($acc) {
            if ($acc['type'] === 'prop' && \property_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']} ?? null;
                if (\is_string($v) && $v !== '') return $v;
            }
            if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']}();
                if (\is_string($v) && $v !== '') return $v;
            }
        }
        return $this->locale->getDefault();
    }
}
