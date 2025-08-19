<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use ReflectionClass;
use ReflectionProperty;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private LocaleContext $localeContext,
        private PropertyAccessorInterface $propertyAccessor,
        private readonly string $fallbackLocale = 'en',
    ) {}

    // --- WRITE PHASE ---------------------------------------------------------

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->ensureSourceRecords($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->ensureSourceRecords($args->getObject());

        // Only required if you actually changed *mapped* fields of the entity being updated.
        $uow  = $args->getObjectManager()->getUnitOfWork();
        $meta = $args->getObjectManager()->getClassMetadata($args->getObject()::class);
        $uow->recomputeSingleEntityChangeSet($meta, $args->getObject());
    }

    /**
     * If we created new Str/StrTranslation during the current flush, we need one extra flush cycle.
     * Guard against infinite recursion with $inSecondFlush.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->needsSecondFlush || $this->inSecondFlush) {
            return;
        }

        $this->inSecondFlush    = true;
        $this->needsSecondFlush = false;

        // Flush the Str/StrTranslation persisted in prePersist/preUpdate.
        $args->getObjectManager()->flush();

        $this->inSecondFlush = false;
    }

    private function ensureSourceRecords(object $entity): void
    {
        // only entities with #[Translatable] attributes
        if (!$config = $this->store->translatableIndex[$entity::class]??null) {
            return;
        }

        $localeField = $config['localeProp']??'locale';
        $srcLocale = $this->propertyAccessor->getValue($entity, $localeField) ?? $this->fallbackLocale;

        $hasCodes = property_exists($entity, 'tCodes');
        /** @var array<string,string> $codes */
        $codes    = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $updated  = false;

        foreach ($config['fields'] as $field=>$meta) {
            $value = $this->propertyAccessor->getValue($entity, $field);
            if (!is_string($value) || trim($value) === '') { continue; }

            /** @var Translatable $meta */
            $hash = $this->store->hash($value, $srcLocale, $meta['context'])??null;

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $updated = true;
            }

            // This persists Str and StrTranslation (but they won't be included in the *current* flush).
            $this->store->upsert(
                hash:      $hash,
                original:  $value,
                srcLocale: $srcLocale,
                context:   $meta['context'],
                locale:    $srcLocale,
                text:      $value
            );

            // Mark that we need a follow-up flush.
            $this->needsSecondFlush = true;
        }

        if ($hasCodes && $updated) {
            // Keep nullable when empty to avoid noisy diffs
            $entity->tCodes = $codes ?: null;
        }
    }

    // --- READ PHASE ----------------------------------------------------------

    public function postLoad(PostLoadEventArgs $args): void
    {
        // only entities with #[Translatable] attributes
        $entity = $args->getObject();
        if (!$config = $this->store->translatableIndex[$entity::class]??null) {
            return;
        }
        $currentLocale = $this->localeContext->get() ?? $this->fallbackLocale;
        /** @var array<string,string> $codes */
        $codes = property_exists($entity, 'tCodes') ? ((array)($entity->tCodes ?? [])) : [];
        foreach ($config['fields'] as $field=>$meta) {
            $value = $this->propertyAccessor->getValue($entity, $field);
            if (!is_string($value) || $value === '') { continue; }

            /** @var Translatable $meta */
            $hash = $codes[$field] ?? $this->store->hash($value, $this->fallbackLocale, $meta['context']);

            $translated = $this->store->get($hash, $currentLocale) ?? $value;
            $this->propertyAccessor->setValue($entity, $field, $translated);
        }

    }

    // --- HELPERS -------------------------------------------------------------

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
