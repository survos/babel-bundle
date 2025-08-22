<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

/**
 * Optional: for entities that persist a str_code_map instead of hook props.
 * Useful for legacy code or alternative resolution approaches.
 */
trait HasBabelTranslationsTrait
{
    /** @var array<string,string>|null map of field => strCode */
    private ?array $str_code_map = null;

    /** @var array<string,string> runtime cache: field => resolved text */
    private array $_resolved_strings = [];

    public function getStrCodeMap(): ?array
    {
        return $this->str_code_map;
    }

    public function setStrCodeMap(?array $map): void
    {
        $this->str_code_map = $map;
    }

    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved_strings[$field] = $text;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved_strings[$field] ?? null;
    }
}
