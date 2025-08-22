<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contract;

/**
 * Entities that expose resolved translations at runtime.
 * Keep this interface focused on RUNTIME access only.
 */
interface TranslatableResolvedInterface
{
    /**
     * Store resolved translation for a given field (non-persisted cache).
     */
    public function setResolvedTranslation(string $field, string $text): void;

    /**
     * Get resolved translation for a given field if available.
     */
    public function getResolvedTranslation(string $field): ?string;

    /**
     * Get raw backing field value (original source text), e.g. "titleBacking".
     * Listeners call this instead of touching private properties.
     */
    public function getBackingValue(string $field): mixed;
}
