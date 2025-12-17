<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Survos\BabelBundle\Contract\TranslatableByHashInterface;

/**
 * Default implementation for TranslatableByHashInterface.
 *
 * - `$tCodes` is intended to be persisted by the using class (often JSON column).
 *   Despite the historical name, it stores: field => str.hash
 * - `$_resolved` is runtime-only (not persisted).
 *
 * Typical usage in a proxy entity:
 *   public string $label { get => $this->translated('label') ?? $this->rawLabel; }
 */
trait TranslatableByHashTrait
{
    /**
     * Persisted pointers: field => str.hash
     *
     * @var array<string,string>|null
     */
    public ?array $tCodes = null;

    /**
     * Runtime-only resolved translations for the current request/run.
     *
     * @var array<string,string|null>
     */
    private array $_resolved = [];

    public function bindTranslatableHash(string $field, string $strHash): void
    {
        $field = trim($field);
        $strHash = trim($strHash);

        if ($field === '' || $strHash === '') {
            return;
        }

        $this->tCodes ??= [];
        $this->tCodes[$field] = $strHash;
    }

    /**
     * @return array<string,string>
     */
    public function getStrHashMap(): array
    {
        $map = $this->tCodes ?? [];
        if ($map === []) {
            return [];
        }

        $out = [];
        foreach ($map as $field => $hash) {
            $field = trim((string) $field);
            $hash  = trim((string) $hash);
            if ($field !== '' && $hash !== '') {
                $out[$field] = $hash;
            }
        }

        return $out;
    }

    public function setResolvedTranslation(string $field, ?string $value): void
    {
        $this->_resolved[$field] = $value;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    protected function translated(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }
}
