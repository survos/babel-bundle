<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use ReflectionClass;
use ReflectionProperty;
use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Contract\TranslatableResolvedInterface;
use Survos\BabelBundle\I18n\ContextLocale;
use Survos\BabelBundle\Service\TranslationStore;

/**
 * Subscriber that:
 *  - prePersist/preUpdate: ensures Str + source StrTranslation exist, updates tCodes (field=>hash)
 *  - postLoad: resolves translations into a NON-persisted cache on the entity (never overwrites mapped fields)
 *  - postFlush: performs a guarded second flush if we created new Str/StrTranslation during the first flush
 */
final class TranslatableSubscriber implements EventSubscriber
{
    private bool $needsSecondFlush = false;
    private bool $inSecondFlush = false;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly ContextLocale $contextLocale,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
            Events::postLoad,
            Events::postFlush,
        ];
    }

    // ---------------- WRITE PHASE ----------------

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->ensureSourceRecords($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $this->ensureSourceRecords($entity);

        // Only needed if you modify mapped fields on the SAME entity
        $uow  = $args->getObjectManager()->getUnitOfWork();
        $meta = $args->getObjectManager()->getClassMetadata($entity::class);
        $uow->recomputeSingleEntityChangeSet($meta, $entity);
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

    private function ensureSourceRecords(object $entity): void
    {
        $rc = new ReflectionClass($entity);
        $srcLocale = $this->detectSourceLocale($entity) ?? $this->fallbackLocale;

        $hasCodes = property_exists($entity, 'tCodes');
        /** @var array<string,string> $codes */
        $codes    = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $updated  = false;

        // Prefer precomputed index from TranslationStore to avoid reflection on every event
        $config = $this->store->getEntityConfig($entity) ?? ['fields' => []];
        $publicFields = array_keys($config['fields'] ?? []);

        // Fallback to reflection if no index available
        if (!$publicFields) {
            $publicFields = array_map(fn(ReflectionProperty $p) => $p->getName(),
                array_filter($rc->getProperties(ReflectionProperty::IS_PUBLIC), fn(ReflectionProperty $p) =>
                    (bool)$p->getAttributes(Translatable::class))
            );
        }

        foreach ($publicFields as $field) {
            $value = property_exists($entity, $field) ? $entity->$field : null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $context = $config['fields'][$field]['context'] ?? $field; // default context = property name
            $hash = $this->store->hash($value, $srcLocale, $context);

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $updated = true;
            }

            // Upsert Str + source-locale StrTranslation
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
        }
    }

    // ---------------- READ PHASE ----------------

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        // If no target locale set in ContextLocale, leave source text intact
        $currentLocale = $this->contextLocale->get();
        if ($currentLocale === null) {
            return;
        }

        // Only store resolved translations in a non-persisted cache (never touch mapped fields)
        if (!$entity instanceof TranslatableResolvedInterface) {
            return;
        }

        $config = $this->store->getEntityConfig($entity) ?? [];
        if (!$config) {
            return;
        }
        $currentLocale = $config['locale'];
        foreach ($config['fields'] ?? [] as $field) {

        }

        $rc = new ReflectionClass($entity);

        /** @var array<string,string> $codes */
        $codes = property_exists($entity, 'tCodes') ? ((array)($entity->tCodes ?? [])) : [];

        $config = $this->store->getEntityConfig($entity) ?? ['fields' => []];
        $publicFields = array_keys($config['fields'] ?? []);

        if (!$publicFields) {
            $publicFields = array_map(fn(ReflectionProperty $p) => $p->getName(),
                array_filter($rc->getProperties(ReflectionProperty::IS_PUBLIC), fn(ReflectionProperty $p) =>
                    (bool)$p->getAttributes(Translatable::class))
            );
        }

        foreach ($publicFields as $field) {
            if (!property_exists($entity, $field)) {
                continue;
            }
            $sourceValue = $entity->$field;
            if (!is_string($sourceValue) || $sourceValue === '') {
                continue;
            }

            $context = $config['fields'][$field]['context'] ?? $field;
            $hash = $codes[$field] ?? $this->store->hash($sourceValue, $this->fallbackLocale, $context);

            $translated = $this->store->get($hash, $currentLocale) ?? $sourceValue;

            // Write to NON-persisted resolved cache
            $entity->setResolvedTranslation($field, $translated);
        }
    }

    // ---------------- HELPERS ----------------

    private function detectSourceLocale(object $entity): ?string
    {
        $rc = new ReflectionClass($entity);

        foreach ($rc->getProperties() as $p) {
            $attrs = $p->getAttributes(BabelLocale::class);
            if (!$attrs) { continue; }
            if (!$p->isPublic()) { $p->setAccessible(true); }
            $v = $p->getValue($entity);
            if (is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }

        if (property_exists($entity, 'locale')) {
            $v = $entity->locale;
            if (is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }

        if (method_exists($entity, 'getLocale')) {
            $v = $entity->getLocale();
            if (is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }

        return null;
    }

    private function normalizeLocale(string $s): string
    {
        $s = str_replace('_', '-', $s);
        if (preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $s, $m)) {
            $lang = strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $s;
    }
}
