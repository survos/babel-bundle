<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;

/**
 * TranslatableSubscriber
 *
 * - Uses ONLY precomputed config from TranslationStore (no Reflection).
 * - prePersist/preUpdate: compute hashes for #[Translatable] fields, ensure Str + source StrTranslation,
 *   and maintain $entity->tCodes (field => hash) if present.
 * - postFlush: single guarded second flush to persist Str/StrTranslation created during the first flush.
 *
 * Assumptions:
 * - TranslationStore::getEntityConfig($entityOrClass) returns:
 *     [
 *       'fields'     => [ fieldName => ['context' => ?string], ... ],
 *       'localeProp' => ?string,  // optional property name on the entity that holds the source locale
 *       'hasTCodes'  => bool,
 *     ]
 * - Entities may have a nullable public array $tCodes; if present, we keep it updated.
 */
final class TranslatableSubscriber implements EventSubscriber
{
    private bool $needsSecondFlush = false;
    private bool $inSecondFlush = false;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly LocaleContext $localeContext,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
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

        // Iterate only the precomputed translatable public fields
        foreach (array_keys($config['fields'] ?? []) as $field) {
            if (!\property_exists($entity, $field)) {
                continue;
            }
            $value = $entity->{$field};
            if (!\is_string($value) || \trim($value) === '') {
                continue;
            }

            $context = $config['fields'][$field]['context'] ?? $field; // default: property name
            $hash = $this->store->hash($value, $srcLocale, $context);

            if (($codes[$field] ?? null) !== $hash) {
                $codes[$field] = $hash;
                $updated = true;
            }

            // Ensure Str + source-locale StrTranslation exist
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
            // keep nullable to avoid noisy diffs when empty
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

        // legacy fallback: public "locale"
        if (\property_exists($entity, 'locale')) {
            $v = $entity->locale;
            if (\is_string($v) && $v !== '') {
                return $this->normalize($v);
            }
        }

        // as a last resort, use the bundle's default (null → caller will coalesce)
        return null;
    }

    private function normalize(string $locale): string
    {
        return \str_replace('_', '-', \trim($locale));
    }
}
