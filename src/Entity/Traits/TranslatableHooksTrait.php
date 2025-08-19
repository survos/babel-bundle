<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Survos\BabelBundle\Runtime\BabelRuntime;

/**
 * Use in entities with #[Translatable] properties that expose hooks.
 * Keep $tCodes persisted; keep $_resolved runtime-only cache.
 */
trait TranslatableHooksTrait
{
    /** @var array<string,string>|null persisted codes: field => hash */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime cache: field => translated text */
    public array $_resolved = [];

    protected function resolveTranslatable(string $field, ?string $backingValue, ?string $context = null): ?string
    {
        if ($backingValue === null || $backingValue === '') {
            return $backingValue;
        }

        // return cached value if present
        if (array_key_exists($field, $this->_resolved)) {
            return $this->_resolved[$field];
        }

        $locale = BabelRuntime::getLocale();
        if ($locale === null) {
            // No target locale set (e.g., CLI without --locale) => return source
            return $backingValue;
        }

        $store = BabelRuntime::getStore();
        $context ??= $field;
        $fallback = BabelRuntime::fallback();

        $codes = (array)($this->tCodes ?? []);
        $hash = $codes[$field] ?? $store->hash($backingValue, $fallback, $context);
        $translated = $store->get($hash, $locale) ?? $backingValue;

        // cache and return
        return $this->_resolved[$field] = $translated;
    }

    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved[$field] = $text;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }
}
