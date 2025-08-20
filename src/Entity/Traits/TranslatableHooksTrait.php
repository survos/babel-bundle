<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

trait TranslatableHooksTrait
{
    /** @var array<string,string>|null field => code/hash (persisted if you map it) */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime resolved translations (field => text) */
    private array $_resolved = [];

    protected function resolveTranslatable(string $field, ?string $backing, ?string $context = null): ?string
    {
        // your existing resolver body (omitted for brevity) ...
        return $backing;
    }

    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved[$field] = $text;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    /** Safe accessor for private/protected backings like $titleBacking */
    public function getBackingValue(string $field): ?string
    {
        $prop = $field . 'Backing';
        return \property_exists($this, $prop) ? $this->{$prop} : null;
    }
}
