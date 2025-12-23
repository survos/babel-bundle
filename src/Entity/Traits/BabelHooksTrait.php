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

    /** @var array<string,mixed> runtime cache: field => resolved term label(s) */
    private array $_resolvedTerms = [];

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

        $displayLocale = BabelRuntime::getLocale();
        if ($displayLocale === null || $displayLocale === '') {
            return $backingValue;
        }

        $sourceLocale = \method_exists(BabelRuntime::class, 'getSourceLocale')
            ? (string) BabelRuntime::getSourceLocale()
            : (string) (BabelRuntime::fallback() ?: 'en');

        $sourceLocale = HashUtil::normalizeLocale($sourceLocale);
        $displayLocale = HashUtil::normalizeLocale($displayLocale);

        $codes   = $this->tCodes ?? [];
        $strHash = $codes[$field] ?? HashUtil::calcSourceKey($backingValue, $sourceLocale);

        $text = BabelRuntime::lookup($strHash, $displayLocale);

        $resolved = ($text !== null && $text !== '') ? $text : $backingValue;

        return $this->_resolved[$field] = $resolved;
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
     * Store resolved term label(s) for a given BabelTerm field.
     *
     * Value is:
     * - string for scalar term fields
     * - array<string> for multiple term fields
     */
    public function setResolvedTerm(string $field, mixed $value): void
    {
        $this->_resolvedTerms[$field] = $value;
    }

    public function getResolvedTerm(string $field): mixed
    {
        return $this->_resolvedTerms[$field] ?? null;
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

        return $this->$prop;
    }
}
