<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

/**
 * Simple accessor for the compile-time index built by BabelTraitAwareScanPass.
 */
final class TranslatableIndex
{
    /** @param array<class-string, array> $index */
    public function __construct(private array $index = []) {}

    /** @return array<class-string, array> */
    public function all(): array { return $this->index; }

    /** @return array|null */
    public function for(string $fqcn): ?array { return $this->index[$fqcn] ?? null; }
}
