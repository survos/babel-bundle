<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use ReflectionProperty;
use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Entity\Traits\HasBabelTranslationsTrait;
use Survos\BabelBundle\Service\TranslationStore;

/**
 * Full lifecycle:
 *  - prePersist/preUpdate: compute hashes for #[Translatable] fields, ensure Str exists,
 *    persist source-locale StrTranslation (optional), and update $tCodes if the entity has it.
 *  - postLoad: replace public #[Translatable] properties with current-locale text (fallback = source text).
 *
 * Source locale detection:
 *  - property **marked with #[BabelLocale]** (preferred)
 *  - else public property "locale"
 *  - else getLocale()
 *  - else fallbackLocale (bundle config)
 */
final class TranslatableSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly string $currentLocale,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::prePersist, Events::preUpdate, Events::postLoad];
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->ensureSourceRecords($args);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->ensureSourceRecords($args);
    }

    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $rc = new \ReflectionClass($entity);

        // tCodes if present
        $codes = property_exists($entity, 'tCodes') ? ((array)($entity->tCodes ?? [])) : [];

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(Translatable::class);
            if (!$attrs) continue;

            $value = $prop->getValue($entity);
            if (!is_string($value) || $value === '') continue;

            $meta = $attrs[0]->newInstance();
            $hash = $codes[$prop->getName()] ?? $this->store->hash($value, $this->fallbackLocale, $meta->context);

            $translated = $this->store->get($hash, $this->currentLocale) ?? $value;
            $prop->setValue($entity, $translated);
        }
    }

    private function ensureSourceRecords(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $rc = new \ReflectionClass($entity);
        dd($entity::class);

        $srcLocale = $this->detectSourceLocale($entity) ?? $this->fallbackLocale;

        $hasCodes = property_exists($entity, 'tCodes');
        $codes = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $updated = false;

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(Translatable::class);
            if (!$attrs) continue;

            $value = $prop->getValue($entity);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $meta = $attrs[0]->newInstance();
            $hash = $this->store->hash($value, $srcLocale, $meta->context);

            if (($codes[$prop->getName()] ?? null) !== $hash) {
                $codes[$prop->getName()] = $hash;
                $updated = true;
            }

            // Ensure Str exists and also source-locale StrTranslation
            $this->store->upsert(
                hash:      $hash,
                original:  $value,
                srcLocale: $srcLocale,
                context:   $meta->context,
                locale:    $srcLocale,
                text:      $value
            );
        }

        if ($hasCodes && $updated) {
            $entity->tCodes = $codes ?: null;
        }
    }

    private function detectSourceLocale(object $entity): ?string
    {
        $rc = new \ReflectionClass($entity);

        // 1) Preferred: any property annotated with #[BabelLocale]
        foreach ($rc->getProperties() as $p) {
            $attrs = $p->getAttributes(BabelLocale::class);
            if (!$attrs) continue;
            if (!$p->isPublic()) { $p->setAccessible(true); }
            $v = $p->getValue($entity);
            if (is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }

        // 2) Legacy: public property "locale"
        if (property_exists($entity, 'locale')) {
            $v = $entity->locale;
            if (is_string($v) && $v !== '') {
                return $this->normalizeLocale($v);
            }
        }

        // 3) Legacy: method getLocale()
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
        // normalize separators and casing: 'es_mx' -> 'es-MX'
        $s = str_replace('_', '-', $s);
        if (preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $s, $m)) {
            $lang = strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $s;
    }
}
