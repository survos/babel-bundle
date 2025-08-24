<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\BabelHasher;

/**
 * Property-mode write path (string-backed fields).
 * Collect on prePersist/preUpdate/onFlush, drain to DBAL on postFlush.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    /** @var array<string, array{hash:string, original:string, src:string}> keyed by hash */
    private array $queueStr = [];
    /** @var array<string, array{hash:string, locale:string, text:string}> keyed by "hash|locale" */
    private array $queueTr  = [];

    /**
     * @param array<int,string> $enabledLocales Inject %kernel.enabled_locales% (optional). If empty, only source is written.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
        private readonly array $enabledLocales = [],
    ) {}

    /* ---------------------------- collectors ---------------------------- */

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->collectFromEntity($args->getObject(), 'prePersist');
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->collectFromEntity($args->getObject(), 'preUpdate');
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $entities = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
        );

        $count = 0;
        foreach ($entities as $e) {
            $count += $this->collectFromEntity($e, 'onFlush');
        }

        $this->logger->info('Babel onFlush collected', [
            'pending'   => $count,
            'queued_str'=> \count($this->queueStr),
            'queued_tr' => \count($this->queueTr),
        ]);
    }

    /**
     * Returns number of fields collected for this entity.
     */
    private function collectFromEntity(object $entity, string $phase): int
    {
        $class  = $entity::class;
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            return 0;
        }

        // Require hooks API, fail fast if missing
        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel collect: entity missing hooks API; skipping.', [
                'class' => $class, 'phase' => $phase,
            ]);
            return 0;
        }

        // Source locale from TranslatableIndex localeProp (#[BabelLocale]) else bundle default
        $srcLocale = $this->resolveSourceLocale($entity, $class);

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        $collected = 0;

        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            $context = $fieldCfg[$field]['context'] ?? null;
            $hash    = BabelHasher::forString($srcLocale, $context, $original);

            // STR (dedupe by hash)
            $this->queueStr[$hash] = [
                'hash'     => $hash,
                'original' => $original,
                'src'      => $srcLocale,
            ];

            // Source TR
            $this->queueTr[$hash.'|'.$srcLocale] = [
                'hash'   => $hash,
                'locale' => $srcLocale,
                'text'   => $original,
            ];

            // Placeholders for other locales
            foreach ($this->enabledLocales as $loc) {
                if (!\is_string($loc) || $loc === '' || $loc === $srcLocale) {
                    continue;
                }
                $k = $hash.'|'.$loc;
                if (!isset($this->queueTr[$k])) {
                    $this->queueTr[$k] = [
                        'hash'   => $hash,
                        'locale' => $loc,
                        'text'   => '',
                    ];
                }
            }

            $collected++;
        }

        return $collected;
    }

    /* ---------------------------- drainer ---------------------------- */

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queueStr === [] && $this->queueTr === []) {
            return;
        }

        $conn = $args->getObjectManager()->getConnection();

        $strWrote = 0;
        foreach ($this->queueStr as $row) {
            $this->upsertStr($conn, $row['hash'], $row['original'], $row['src']);
            $strWrote++;
        }

        $trWrote = 0;
        foreach ($this->queueTr as $row) {
            $this->upsertTranslation($conn, $row['hash'], $row['locale'], $row['text']);
            $trWrote++;
        }

        $this->queueStr = [];
        $this->queueTr  = [];

        $this->logger->info('Babel postFlush drained', ['str' => $strWrote, 'tr' => $trWrote]);
    }

    /* ---------------------------- DB helpers ---------------------------- */

    private function upsertStr(Connection $conn, string $hash, string $original, string $src): void
    {
        $now = 'CURRENT_TIMESTAMP';

        $sqlUpdate = 'UPDATE str
                      SET original = :original,
                          src_locale = :src,
                          updated_at = '.$now.'
                      WHERE hash = :hash';
        $updated = $conn->executeStatement($sqlUpdate, [
            'original' => $original, 'src' => $src, 'hash' => $hash,
        ]);

        if ($updated > 0) {
            return;
        }

        try {
            $sqlInsert = 'INSERT INTO str (hash, original, src_locale, created_at, updated_at)
                          VALUES (:hash, :original, :src, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, [
                'hash' => $hash, 'original' => $original, 'src' => $src,
            ]);
        } catch (\Throwable) {
            $conn->executeStatement($sqlUpdate, [
                'original' => $original, 'src' => $src, 'hash' => $hash,
            ]);
        }
    }

    private function upsertTranslation(Connection $conn, string $hash, string $locale, string $text): void
    {
        $now = 'CURRENT_TIMESTAMP';

        $sqlUpdate = 'UPDATE str_translation
                      SET text = :text,
                          updated_at = '.$now.'
                      WHERE hash = :hash AND locale = :locale';
        $updated = $conn->executeStatement($sqlUpdate, [
            'text' => $text, 'hash' => $hash, 'locale' => $locale,
        ]);

        if ($updated > 0) {
            return;
        }

        try {
            $sqlInsert = 'INSERT INTO str_translation (hash, locale, text, created_at, updated_at)
                          VALUES (:hash, :locale, :text, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, [
                'hash' => $hash, 'locale' => $locale, 'text' => $text,
            ]);
        } catch (\Throwable) {
            $conn->executeStatement($sqlUpdate, [
                'text' => $text, 'hash' => $hash, 'locale' => $locale,
            ]);
        }
    }

    /**
     * Source locale from TranslatableIndex (#[BabelLocale] → localeProp), else bundle default.
     */
    private function resolveSourceLocale(object $entity, string $class): string
    {
        $current = $this->locale->get();
        $default = \method_exists($this->locale, 'getDefault') ? (string)$this->locale->getDefault() : $current;

        $cfg  = $this->index->configFor($class);
        $prop = \is_string($cfg['localeProp'] ?? null) ? $cfg['localeProp'] : null;

        if ($prop !== null && $prop !== '') {
            $getter = 'get' . \ucfirst($prop);
            if (\method_exists($entity, $getter)) {
                $val = $entity->$getter();
                if (\is_string($val) && $val !== '') {
                    return $val;
                }
            }
            if (\property_exists($entity, $prop)) {
                $val = $entity->$prop ?? null;
                if (\is_string($val) && $val !== '') {
                    return $val;
                }
            }
        }

        return $default;
    }
}
