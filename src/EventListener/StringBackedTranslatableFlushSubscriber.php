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
 * String-backed write-side: NO runtime attribute parsing.
 * Uses TranslatableIndex for fields, LocaleContext for source locale,
 * and BabelHasher for a consistent key.
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
        $this->collect($args->getObjectManager()->getUnitOfWork());

        $this->logger->info('Babel onFlush collected', [
            'pending' => \count($this->pending),
            'texts'   => \count($this->pendingWithText),
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
        $nowExpr  = $platform instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';

        $strTable = 'str';
        $trTable  = 'str_translation';

        // STR upsert
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$nowExpr}, {$nowExpr})
                       ON CONFLICT (hash) DO UPDATE
                         SET original = EXCLUDED.original,
                             src_locale = EXCLUDED.src_locale,
                             updated_at = {$nowExpr}";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$nowExpr}, {$nowExpr})
                       ON CONFLICT(hash) DO UPDATE SET
                         original = excluded.original,
                         src_locale = excluded.src_locale,
                         updated_at = {$nowExpr}";
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            $sqlStr = "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                       VALUES (:hash, :original, :src, {$nowExpr}, {$nowExpr})
                       ON DUPLICATE KEY UPDATE
                         original = VALUES(original),
                         src_locale = VALUES(src_locale),
                         updated_at = {$nowExpr}";
        } else {
            $this->logger->error('Unsupported DB platform', ['platform' => $platform::class]);
            return;
        }

        // TR ensure (nullable text => NULL)
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$nowExpr}, {$nowExpr})
                            ON CONFLICT (hash, locale) DO NOTHING";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$nowExpr}, {$nowExpr})
                            ON CONFLICT(hash, locale) DO NOTHING";
        } else {
            $sqlTrEnsure = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, NULL, {$nowExpr}, {$nowExpr})
                            ON DUPLICATE KEY UPDATE text = text";
        }

        // TR upsert text
        if ($platform instanceof PostgreSQLPlatform) {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$nowExpr}, {$nowExpr})
                            ON CONFLICT (hash, locale) DO UPDATE
                              SET text = EXCLUDED.text, updated_at = {$nowExpr}";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$nowExpr}, {$nowExpr})
                            ON CONFLICT(hash, locale) DO UPDATE SET
                              text = excluded.text, updated_at = {$nowExpr}";
        } else {
            $sqlTrUpsert = "INSERT INTO {$trTable} (hash, locale, text, created_at, updated_at)
                            VALUES (:hash, :locale, :text, {$nowExpr}, {$nowExpr})
                            ON DUPLICATE KEY UPDATE
                              text = VALUES(text), updated_at = {$nowExpr}";
        }

        $locales = $this->locale->getEnabled() ?: [$this->locale->getDefault()];
        \assert($locales !== [], 'enabled_locales must not be empty');

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
                        'locale' => (string) $loc,
                    ]);
                }
            }

            foreach ($this->pendingWithText as $r) {
                $conn->executeStatement($sqlTrUpsert, $r);
            }

            if ($started) {
                $conn->commit();
            }
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
     * Walk scheduled entities and enqueue rows using the compile-time index.
     * Backing prop convention: "<field>_backing".
     * Source locale precedence: entity->srcLocale ?? LocaleContext->get()
     */
    private function collect(UnitOfWork $uow): void
    {
        $entities = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        );

        foreach ($entities as $e) {
            $class  = $e::class;
            $fields = $this->index->fieldsFor($class);
            if ($fields === []) {
                continue;
            }

            $src = null;
            if (\property_exists($e, 'srcLocale')) {
                $src = \is_string($e->srcLocale) && $e->srcLocale !== '' ? $e->srcLocale : null;
            }
            $srcLocale = $src ?? $this->locale->get();

            $cfg = $this->index->configFor($class);
            $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

            foreach ($fields as $field) {
                $backing = $field . '_backing';
                if (!\property_exists($e, $backing)) {
                    continue;
                }
                $original = $e->$backing ?? null;
                if (!\is_string($original) || $original === '') {
                    continue;
                }

                $context = $fieldCfg[$field]['context'] ?? null;
                $hash = BabelHasher::forString($srcLocale, $context, $original);

                $this->pending[$hash] = [
                    'original' => $original,
                    'src'      => $srcLocale,
                ];

                // Optional immediate texts: $_pendingTranslations[field][locale] = text
                if (\property_exists($e, '_pendingTranslations') && \is_array($e->_pendingTranslations ?? null)) {
                    $pairs = $e->_pendingTranslations[$field] ?? null;
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
            }
        }
    }
}
