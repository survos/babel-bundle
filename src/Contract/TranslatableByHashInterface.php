<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contract;

/**
 * Pointer-driven translations (hash-based).
 *
 * For proxy models (Pixie-style) that don't have discoverable #[Translatable] properties,
 * store a per-field pointer map where the pointer is the canonical STR key: `str.hash`.
 *
 * Hydration/listeners can then resolve translations by (str_hash, locale) and call
 * setResolvedTranslation(field, value) for the current request/run.
 */
interface TranslatableByHashInterface
{
    /**
     * @return array<string,string> field => str.hash
     */
    public function getStrHashMap(): array;

    public function setResolvedTranslation(string $field, ?string $value): void;
}
