<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Survos\BabelBundle\Runtime\BabelRuntime;

/**
 * Attach to entities with #[Translatable] fields.
 * - $tCodes holds field => hash (persisted if you map it)
 * - resolveTranslatable() returns resolved text using BabelRuntime
 * - getBackingValue() lets listeners safely access private/protected backings
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

        // Return cached if present
        if (\array_key_exists($field, $this->_resolved)) {
            return $this->_resolved[$field];
        }

        // If runtime locale is not set (e.g. CLI without init), return source
        $locale = BabelRuntime::getLocale();
        if ($locale === null) {
            return $backingValue;
        }

        $context ??= $field;
        $fallback = BabelRuntime::fallback();

        $codes = (array)($this->tCodes ?? []);
        $hash  = $codes[$field] ?? BabelRuntime::hash($backingValue, $fallback, $context);
        $text  = BabelRuntime::lookup($hash, $locale) ?? $backingValue;
        dd($text);

        return $this->_resolved[$field] = $text;
    }

    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved[$field] = $text;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    /**
     * Safe accessor for raw source/backing content, used by listeners.
     * Tries "$field" then "$fieldBacking".
     */
    public function getBackingValue(string $field): mixed
    {
        if (\property_exists($this, $field)) {
            return $this->$field ?? null;
        }
        $backing = $field . 'Backing';
        if (\property_exists($this, $backing)) {
            return $this->$backing ?? null;
        }
        return null;
    }
}
