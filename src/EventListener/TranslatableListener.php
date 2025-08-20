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

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->ensureSourceRecords($args->getObject(), $args->getObjectManager()->getClassMetadata($args->getObject()::class));
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
        if (!$this->needsSecondFlush || $this->inSecondFlush) return;
        $this->inSecondFlush = true;
        $this->needsSecondFlush = false;
        $args->getObjectManager()->flush();
        $this->inSecondFlush = false;
    }

    private function ensureSourceRecords(object $entity, \Doctrine\ORM\Mapping\ClassMetadata $meta): bool
    {
        $config = $this->store->getEntityConfig($entity) ?? null;
        if (!$config) return false;

        // source locale from config or fallback
        $srcLocale = null;
        $prop = $config['localeProp'] ?? 'locale';
        if (\property_exists($entity, $prop)) {
            $v = $this->pa->getValue($entity, $prop);
            if (\is_string($v) && $v !== '') $srcLocale = $this->normalize($v);
        }
        $srcLocale ??= $this->fallbackLocale;

        $hasCodes = ($config['hasTCodes'] ?? false) && \property_exists($entity, 'tCodes');
        /** @var array<string,string> $codes */
        $codes   = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $changed = false;

        foreach (array_keys($config['fields'] ?? []) as $field) {
            if (!\property_exists($entity, $field)) continue;

            // SAFE: prefer getBackingValue() to access private backings
            $value = \method_exists($entity, 'getBackingValue')
                ? $entity->getBackingValue($field)
                : $this->pa->getValue($entity, $field);

            if (!\is_string($value) || \trim($value) === '') continue;

            $context = $config['fields'][$field]['context'] ?? $field;
            $hash    = $this->store->hash($value, $srcLocale, $context);

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $changed = true;
            }

            // Ensure Str + source translation
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

        if ($hasCodes && $changed) {
            $entity->tCodes = $codes ?: null;
        }

        return $hasCodes && $changed;
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $config = $this->store->getEntityConfig($entity) ?? null;
        if (!$config) return;

        $targetLocale = $this->normalize($this->localeContext->get() ?? $this->fallbackLocale);
        $srcLocale    = $this->fallbackLocale; // consider detecting per entity if you track it

        /** @var array<string,string> $codes */
        $codes = \property_exists($entity, 'tCodes') ? ((array)($entity->tCodes ?? [])) : [];

        foreach ($config['fields'] as $field => $info) {
            if (!\property_exists($entity, $field)) continue;

            $sourceValue = \method_exists($entity, 'getBackingValue')
                ? $entity->getBackingValue($field)
                : $this->pa->getValue($entity, $field);

            if (!\is_string($sourceValue) || $sourceValue === '') continue;

            $context = $info['context'] ?? $field;
            $hash    = $codes[$field] ?? $this->store->hash($sourceValue, $srcLocale, $context);
            $text    = $this->store->get($hash, $targetLocale) ?? $sourceValue;

            if (\method_exists($entity, 'setResolvedTranslation')) {
                $entity->setResolvedTranslation($field, $text);
            }
        }
    }

    private function normalize(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $locale;
    }
}
