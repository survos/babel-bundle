<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TranslatableListener
{
    private bool $needsSecondFlush = false;
    private bool $inSecondFlush = false;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly LocaleContext $localeContext,
        private readonly PropertyAccessorInterface $pa,
        private readonly string $fallbackLocale = 'en',
    ) {}

    // ---------------- WRITE PHASE ----------------

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $meta   = $args->getObjectManager()->getClassMetadata($entity::class);
        $this->ensureSourceRecords($entity, $meta);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $om     = $args->getObjectManager();
        $meta   = $om->getClassMetadata($entity::class);

        $updated = $this->ensureSourceRecords($entity, $meta);

        if ($updated) {
            $om->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->needsSecondFlush || $this->inSecondFlush) {
            return;
        }
        $this->inSecondFlush = true;
        $this->needsSecondFlush = false;
        $args->getObjectManager()->flush();
        $this->inSecondFlush = false;
    }

    /**
     * Ensure Str + source StrTranslation exist; update tCodes if present.
     * Returns true if we changed mapped state on the entity (e.g. tCodes).
     */
    private function ensureSourceRecords(object $entity, \Doctrine\ORM\Mapping\ClassMetadata $meta): bool
    {
        $config = $this->store->getEntityConfig($entity) ?? null;
        if (!$config) {
            return false;
        }

        $srcLocale = $this->readSourceLocale($entity, $config) ?? $this->fallbackLocale;

        $hasCodes = ($config['hasTCodes'] ?? false) && \property_exists($entity, 'tCodes');
        /** @var array<string,string> $codes */
        $codes   = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $updated = false;

        foreach (array_keys($config['fields'] ?? []) as $field) {
            // Prefer backing value if present to avoid reading hook-resolved text
            $backing = $field.'Backing';
            $value = \property_exists($entity, $backing)
                ? $entity->{$backing}
                : $this->pa->getValue($entity, $field);

            if (!\is_string($value) || \trim($value) === '') {
                continue;
            }

            $context = $config['fields'][$field]['context'] ?? $field;
            $hash    = $this->store->hash($value, $srcLocale, $context);

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $updated = true;
            }

            $this->store->upsert(
                hash:      $hash,
                original:  $value,
                srcLocale: $srcLocale,
                context:   $context,
                locale:    $srcLocale,
                text:      $value
            );

            $this->needsSecondFlush = true;
        }

        if ($hasCodes && $updated) {
            $entity->tCodes = $codes ?: null;
            return true;
        }

        return false;
    }

    // ---------------- READ PHASE ----------------

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $config = $this->store->getEntityConfig($entity) ?? null;
        if (!$config) {
            return;
        }

        $targetLocale = $this->normalizeLocale($this->localeContext->get() ?? $this->fallbackLocale);
        $meta         = $args->getObjectManager()->getClassMetadata($entity::class);

        // Determine source locale for hash calculation when tCodes is empty
        $srcLocale = $this->readSourceLocale($entity, $config) ?? $this->fallbackLocale;

        /** @var array<string,string> $codes */
        $codes = \property_exists($entity, 'tCodes') ? ((array)($entity->tCodes ?? [])) : [];

        foreach ($config['fields'] as $field => $info) {
            if (!\property_exists($entity, $field)) {
                continue;
            }

            // Prefer backing to avoid reading already-resolved values via hook getter
            $backing = $field.'Backing';
            $sourceValue = \property_exists($entity, $backing)
                ? $entity->{$backing}
                : $this->pa->getValue($entity, $field);

            if (!\is_string($sourceValue) || $sourceValue === '') {
                continue;
            }

            $context = $info['context'] ?? $field;
            $hash    = $codes[$field] ?? $this->store->hash($sourceValue, $srcLocale, $context);
            $text    = $this->store->get($hash, $targetLocale) ?? $sourceValue;

            // Prefer a non-persisted resolved cache on the entity
            if (\method_exists($entity, 'setResolvedTranslation')) {
                $entity->setResolvedTranslation($field, $text);
                continue;
            } else {
                dd("no setResolvedTranslation method on " . $entity::class);
            }

            // Only write to the field when it's NOT a mapped Doctrine column
            if (!$meta->hasField($field)) {
                $this->pa->setValue($entity, $field, $text);
            }
        }
    }

    // ---------------- HELPERS ----------------

    /**
     * Read source locale from configured localeProp (or 'locale').
     *
     * @param array{localeProp?:?string} $config
     */
    private function readSourceLocale(object $entity, array $config): ?string
    {
        $prop = $config['localeProp'] ?? 'locale';
        if (\property_exists($entity, $prop)) {
            $v = $this->pa->getValue($entity, $prop);
            if (\is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }
        return null;
    }

    private function normalizeLocale(string $s): string
    {
        $s = \str_replace('_', '-', \trim($s));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $s, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $s;
    }
}
