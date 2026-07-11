<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contract;

/**
 * Pointer-driven translations (hash-based).
 *
 * Built for models whose translatable content lives inside a JSON blob column rather than
 * discoverable typed properties — folio-bundle's Row is the canonical example: its `dtoData`
 * column holds arbitrary per-dataset fields (title, description, ...), so the property-attribute
 * scan babel uses for Term/TermSet (#[Translatable] on a real typed property) can't see into it.
 * ("Pixie" was this model's old working name before the rename to "Folio" — you may still see it
 * in older comments/commits.)
 *
 * Implementing entities persist a field => str.hash pointer map (see
 * Survos\BabelBundle\Entity\Traits\TranslatableByHashTrait — the ready-made implementation:
 * $tCodes is the persisted map, bindTranslatableHash() populates it when seeding Str rows,
 * getStrHashMap()/setResolvedTranslation() satisfy this interface).
 *
 * Hydration/listeners can then resolve translations by (str_hash, locale) and call
 * setResolvedTranslation(field, value) for the current request/run — see
 * Survos\BabelBundle\EventListener\BabelHashPointerPostLoadHydrator, which does exactly this on
 * Doctrine's postLoad event for any entity implementing this interface.
 */
interface TranslatableByHashInterface
{
    /**
     * @return array<string,string> field => str.hash
     */
    public function getStrHashMap(): array;

    public function setResolvedTranslation(string $field, ?string $value): void;
}
