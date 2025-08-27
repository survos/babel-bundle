<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

/**
 * Map shape:
 * [
 *   FQCN => [
 *     'fields'          => [ fieldName => ['context' => ?string], ... ],
 *     'localeAccessor'  => ['type'=>'prop'|'method','name'=>string,'format'=>?string] | null,
 *     'hasTCodes'       => bool,
 *     ...
 *   ],
 * ]
 */
final class TranslatableIndex
{
    /** @param array<string, array> $map */
    public function __construct(private readonly array $map = []) {}

    public function all(): array { return $this->map; }

    public function configFor(string $class): ?array { return $this->map[$class] ?? null; }

    /** @return list<string> */
    public function fieldsFor(string $class): array
    {
        $cfg = $this->map[$class] ?? null;
        return $cfg && !empty($cfg['fields']) ? array_keys($cfg['fields']) : [];
    }

    /** @return array{type:string,name:string,format:?string}|null */
    public function localeAccessorFor(string $class): ?array
    {
        $cfg = $this->map[$class] ?? null;
        /** @var array{type:string,name:string,format:?string}|null $acc */
        $acc = $cfg['localeAccessor'] ?? null;
        return $acc;
    }
}
