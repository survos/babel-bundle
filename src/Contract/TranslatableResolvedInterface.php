<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contract;

/**
 * Entities implementing this interface agree to:
 *  - expose a persisted map (tCodes) field=>hash (or provide equivalent)
 *  - accept resolved translations into a NON-persisted cache
 */
interface TranslatableResolvedInterface
{
//    /** @return array<string,string> field => code/hash */
//    public function getTranslatableCodeMap(): array;

    /** Store the resolved, non-persisted translation for a field */
    public function setResolvedTranslation(string $field, string $text): void;

    /** Read the resolved translation, if any */
    public function getResolvedTranslation(string $field): ?string;
}
