<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Survos\BabelBundle\Runtime\BabelRuntime;

/**
 * Attach to entities with #[Translatable] fields.
 * - $tCodes holds field => hash (persisted if you map it)
 * - resolveTranslatable() returns resolved text using BabelRuntime
 * - getBackingValue() lets listeners safely access backings
 */
trait TranslatableHooksTrait
{
    /** @var array<string,string>|null persisted codes: field => hash */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime cache: field => translated text */
    private array $_resolved = [];

    protected function resolveTranslatable(string $field, ?string $backingValue, ?string $context = null): ?string
    {
        if ($backingValue === null || $backingValue === '') {
            return $backingValue;
        }

        // Return cache if present
        if (\array_key_exists($field, $this->_resolved)) {
            return $this->_resolved[$field];
        }

        // If runtime locale is not set (e.g. CLI without init), return source
        $locale = BabelRuntime::getLocale();
        if ($locale === null) {
            return $backingValue;
        }

        $context ??= $field;

        // Prefer mapped code; otherwise compute via runtime hasher (must match BabelHasher)
        $codes = (array)($this->tCodes ?? []);
        $hash  = $codes[$field] ?? BabelRuntime::hash($backingValue, BabelRuntime::fallback(), $context);

        $text  = BabelRuntime::lookup($hash, $locale) ?? $backingValue;
        return $this->_resolved[$field] = $text;
    }

    public function setResolvedTranslation(string $field, ?string $text): void
    {
        if ($text !== null) {
            $this->_resolved[$field] = $text;
        }
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    /**
     * Safe accessor for raw source/backing content, used by listeners.
     * Tries snake case "<field>_backing", then camel "<field>Backing", then "<field>".
     */
    public function getBackingValue(string $field): mixed
    {
        $snake = $field . '_backing';
        if (\property_exists($this, $snake)) {
            return $this->$snake ?? null;
        }
        $camel = $field . 'Backing';
        if (\property_exists($this, $camel)) {
            return $this->$camel ?? null;
        }
        if (\property_exists($this, $field)) {
            return $this->$field ?? null;
        }
        return null;
    }
}
