<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

/**
 * Persist codes in $tCodes; keep resolved translations in $_resolved (NOT persisted).
 */
trait HasBabelTranslationsTrait
{
    /** @var array<string,string>|null persisted: field => code/hash */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime only: field => translated text */
    private array $_resolved = [];

    public function getTranslatableCodeMap(): array
    {
        return (array)($this->tCodes ?? []);
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
