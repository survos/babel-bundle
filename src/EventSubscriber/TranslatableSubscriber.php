<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;

/**
 * Attribute-driven subscriber:
 *  - getEntityConfig() is precomputed by the bundle's compiler pass (attributes scan).
 *  - prePersist/preUpdate: compute hashes for #[Translatable] fields, ensure Str & source StrTranslation
 *    via DBAL upsert (identity-map safe), and maintain $entity->tCodes if present.
 */
final class TranslatableSubscriber implements EventSubscriber
{
    private bool $needsSecondFlush = false; // kept if you have other entities to flush
    private bool $inSecondFlush = false;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly LocaleContext $localeContext,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::prePersist, Events::preUpdate, Events::postFlush];
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

        // If mapped fields on this entity were touched, recompute its changeset
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
        $config = $this->store->getEntityConfig($entity) ?? [];
        if (!$config) {
            return; // not a translatable entity
        }

        // Determine source locale
        $srcLocale = $this->readSourceLocale($entity, $config) ?? $this->fallbackLocale;

        // Prepare $tCodes holder if present
        $hasCodes = ($config['hasTCodes'] ?? false) && \property_exists($entity, 'tCodes');
        /** @var array<string,string> $codes */
        $codes   = $hasCodes ? ((array)($entity->tCodes ?? [])) : [];
        $updated = false;

        // Iterate precomputed translatable public fields
        foreach (array_keys($config['fields'] ?? []) as $field) {
            if (!\property_exists($entity, $field)) {
                continue;
            }

            // Prefer backing value if using property hooks
            $value = \method_exists($entity, 'getBackingValue')
                ? $entity->getBackingValue($field)
                : ($entity->{$field} ?? null);

            if (!\is_string($value) || \trim($value) === '') {
                continue;
            }

            $context = $config['fields'][$field]['context'] ?? $field;
            $hash    = $this->store->hash($value, $srcLocale, $context);

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $updated = true;
            }

            // DBAL raw upsert (no ORM entity creation → no identity collisions)
            $this->store->upsertRaw(
                hash:          $hash,
                original:      $value,
                srcLocale:     $srcLocale,
                context:       $context,
                localeForText: $srcLocale,
                text:          $value
            );

            // No need for a second flush for Str tables; DBAL wrote immediately
            // Keep the flag in case your entity itself needs another flush round
            $this->needsSecondFlush = $this->needsSecondFlush || false;
        }

        if ($hasCodes && $updated) {
            $entity->tCodes = $codes ?: null;
        }
    }

    /**
     * Read source locale from precomputed config (preferred localeProp),
     * otherwise fall back to common patterns, then null.
     *
     * @param array{localeProp?:?string} $config
     */
    private function readSourceLocale(object $entity, array $config): ?string
    {
        $prop = $config['localeProp'] ?? null;
        if ($prop && \property_exists($entity, $prop)) {
            $v = $entity->{$prop};
            if (\is_string($v) && $v !== '') {
                return $this->normalize($v);
            }
        }

        if (\property_exists($entity, 'locale')) {
            $v = $entity->locale;
            if (\is_string($v) && $v !== '') {
                return $this->normalize($v);
            }
        }

        return null;
    }

    private function normalize(string $locale): string
    {
        return \str_replace('_', '-', \trim($locale));
    }
}
