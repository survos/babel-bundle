<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Survos\Lingua\Core\Identity\HashUtil;
use Survos\BabelBundle\Runtime\BabelRuntime;

/**
 * Attach to entities with #[Translatable] property hooks.
 *
 * Conventions (strict):
 * - Backing property MUST be named: "<field>Backing" (camelCase)
 * - tCodes (if mapped/persisted) is field => str_hash
 *
 * Runtime:
 * - resolveTranslatable() returns resolved text for the current BabelRuntime locale
 * - getBackingValue() is a strict accessor used by listeners (no silent null)
 */
trait BabelHooksTrait
{
    /**
     * Optional persisted codes: field => str_hash.
     * If present, we use it instead of recomputing the str_hash.
     *
     * @var array<string,string>|null
     */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime cache: field => resolved text */
    private array $_resolved = [];

    /**
     * Resolve a translatable field for the current runtime locale.
     *
     * @param string      $field        logical field name (e.g. "title")
     * @param string|null $backingValue backing/original source value
     * @param string|null $context      optional context for debugging/metadata only (not used in key)
     */
    protected function resolveTranslatable(string $field, ?string $backingValue, ?string $context = null): ?string
    {
        if ($backingValue === null || $backingValue === '') {
            return $backingValue;
        }

        if (\array_key_exists($field, $this->_resolved)) {
            return $this->_resolved[$field];
        }

        // If runtime locale is not set (e.g. CLI without init), return source
        $displayLocale = BabelRuntime::getLocale();
        if ($displayLocale === null || $displayLocale === '') {
            return $backingValue;
        }

        // IMPORTANT:
        // The str_hash must be computed using the *source* locale for this entity.
        // BabelRuntime::fallback() is legacy; do not use it as a source locale.
        //
        // We use BabelRuntime::getSourceLocale() if available; otherwise we default
        // to BabelRuntime::fallback() only as an absolute last resort.
        //
        // If you have a LocaleContext in your app, prefer setting BabelRuntime explicitly.
        $sourceLocale = \method_exists(BabelRuntime::class, 'getSourceLocale')
            ? (string) BabelRuntime::getSourceLocale()
            : (string) (BabelRuntime::fallback() ?: 'en');

        $sourceLocale = HashUtil::normalizeLocale($sourceLocale);
        $displayLocale = HashUtil::normalizeLocale($displayLocale);

        // Prefer persisted code if present; otherwise compute canonical source hash
        $codes  = $this->tCodes ?? [];
        $strHash = $codes[$field] ?? HashUtil::calcSourceKey($backingValue, $sourceLocale);

        // Lookup resolved translation by (str_hash, displayLocale)
        // NOTE: BabelRuntime::lookup expects a str_hash for StrTranslation lookup.
        $text = BabelRuntime::lookup($strHash, $displayLocale);

        // Fallback to source if not found
        $resolved = ($text !== null && $text !== '') ? $text : $backingValue;

        return $this->_resolved[$field] = $resolved;
    }

    /**
     * Store resolved translation for a given field (non-persisted runtime cache).
     */
    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved[$field] = $text;
    }

    /**
     * Get resolved translation for a given field if available.
     */
    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    /**
     * Strict accessor for raw source/backing content, used by listeners.
     *
     * Conventions:
     * - backing property MUST be "<field>Backing"
     *
     * @throws \LogicException if the backing property does not exist
     */
    public function getBackingValue(string $field): mixed
    {
        $prop = $field . 'Backing';

        if (!\property_exists($this, $prop)) {
            throw new \LogicException(sprintf(
                'BabelHooksTrait: missing backing property "%s" on %s. Expected convention: <field>Backing.',
                $prop,
                static::class
            ));
        }

        // Works even for private properties within the defining class/trait context.
        return $this->$prop;
    }
}
