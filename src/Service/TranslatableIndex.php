<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

/**
 * Holds the compile-time translatable index.
 *
 * Expected normalized shape (done in the compiler pass):
 * [
 *   FQCN => [
 *     'fields'     => [ fieldName => ['context' => ?string], ... ],
 *     'localeProp' => ?string,
 *     'hasTCodes'  => bool,
 *   ],
 *   ...
 * ]
 */
final class TranslatableIndex
{
    /**
     * @param array<string, array{
     *   fields: array<string, array{context:?string}>,
     *   localeProp: ?string,
     *   hasTCodes: bool
     * }> $map
     */
    public function __construct(private readonly array $map = [])
    {
    }

    /** Whole map (debug/inspection). */
    public function all(): array
    {
        return $this->map;
    }

    /** Config for a given class or null. */
    public function configFor(string $class): ?array
    {
        return $this->map[$class] ?? null;
    }

    /** Just the translatable field names for a class. */
    public function fieldsFor(string $class): array
    {
        $cfg = $this->map[$class] ?? null;
        return $cfg && !empty($cfg['fields']) ? array_keys($cfg['fields']) : [];
    }

    /** Whether an entry exists for this class. */
    public function has(string $class): bool
    {
        return isset($this->map[$class]);
    }
}
