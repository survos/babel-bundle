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

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    /** @var array<string, array{hash:string, original:string, src:string}> */
    private array $queueStr = [];
    /** @var array<string, array{hash:string, locale:string, text:string}> */
    private array $queueTr  = [];

    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
        private readonly array $enabledLocales = [],
    ) {}

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

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        $collected = 0;

        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') continue;

            $context = $fieldCfg[$field]['context'] ?? null;
            $hash    = BabelHasher::forString($srcLocale, $context, $original);

            // STR (dedupe by hash)
            $this->queueStr[$hash] = ['hash'=>$hash,'original'=>$original,'src'=>$srcLocale];

            // Source TR
            $this->queueTr[$hash.'|'.$srcLocale] = ['hash'=>$hash,'locale'=>$srcLocale,'text'=>$original];

            // Placeholders (insert-if-missing)
            foreach ($this->enabledLocales as $loc) {
                if (!\is_string($loc) || $loc === '' || $loc === $srcLocale) continue;
                $k = $hash.'|'.$loc;
                if (!isset($this->queueTr[$k])) {
                    $this->queueTr[$k] = ['hash'=>$hash,'locale'=>$loc,'text'=>'']; // marker
                }
            }

            $collected++;
        }

        return $collected;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queueStr === [] && $this->queueTr === []) return;

        $conn = $args->getObjectManager()->getConnection();

        foreach ($this->queueStr as $row) {
            $this->upsertStr($conn, $row['hash'], $row['original'], $row['src']);
        }

        foreach ($this->queueTr as $row) {
            if ($row['text'] === '') {
                $this->insertPlaceholderIfMissing($conn, $row['hash'], $row['locale']);
            } else {
                $this->upsertTranslation($conn, $row['hash'], $row['locale'], $row['text']);
            }
        }

        $this->queueStr = [];
        $this->queueTr  = [];
        $this->logger->info('Babel postFlush drained');
    }

    /* DB helpers */

    private function upsertStr(Connection $conn, string $hash, string $original, string $src): void
    {
        $now = 'CURRENT_TIMESTAMP';
        $sqlUpdate = 'UPDATE str SET original=:original, src_locale=:src, updated_at='.$now.' WHERE hash=:hash';
        $updated = $conn->executeStatement($sqlUpdate, ['original'=>$original,'src'=>$src,'hash'=>$hash]);
        if ($updated > 0) return;

        try {
            $sqlInsert = 'INSERT INTO str (hash, original, src_locale, created_at, updated_at)
                          VALUES (:hash, :original, :src, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, ['hash'=>$hash,'original'=>$original,'src'=>$src]);
        } catch (\Throwable) {
            $conn->executeStatement($sqlUpdate, ['original'=>$original,'src'=>$src,'hash'=>$hash]);
        }
    }

    private function upsertTranslation(Connection $conn, string $hash, string $locale, string $text): void
    {
        $now = 'CURRENT_TIMESTAMP';
        $sqlUpdate = 'UPDATE str_translation SET text=:text, updated_at='.$now.' WHERE hash=:hash AND locale=:loc';
        $updated = $conn->executeStatement($sqlUpdate, ['text'=>$text,'hash'=>$hash,'loc'=>$locale]);
        if ($updated > 0) return;

        try {
            $sqlInsert = 'INSERT INTO str_translation (hash, locale, text, created_at, updated_at)
                          VALUES (:hash, :loc, :text, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, ['hash'=>$hash,'loc'=>$locale,'text'=>$text]);
        } catch (\Throwable) {
            $conn->executeStatement($sqlUpdate, ['text'=>$text,'hash'=>$hash,'loc'=>$locale]);
        }
    }

    private function insertPlaceholderIfMissing(Connection $conn, string $hash, string $locale): void
    {
        $now = 'CURRENT_TIMESTAMP';
        try {
            $sqlInsert = 'INSERT INTO str_translation (hash, locale, text, created_at, updated_at)
                          VALUES (:hash, :loc, :text, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, ['hash'=>$hash,'loc'=>$locale,'text'=>'']);
        } catch (\Throwable) {
            // exists – noop
        }
    }

    private function resolveSourceLocale(object $entity, string $class): string
    {
        $current = $this->locale->get();
        $default = \method_exists($this->locale, 'getDefault') ? (string)$this->locale->getDefault() : $current;

        $cfg  = $this->index->configFor($class);
        $prop = \is_string($cfg['localeProp'] ?? null) ? $cfg['localeProp'] : null;

        if ($prop) {
            $getter = 'get'.\ucfirst($prop);
            if (\method_exists($entity, $getter)) {
                $val = $entity->$getter();
                if (\is_string($val) && $val !== '') return $val;
            }
            if (\property_exists($entity, $prop)) {
                $val = $entity->$prop ?? null;
                if (\is_string($val) && $val !== '') return $val;
            }
        }
        return $default;
    }
}
