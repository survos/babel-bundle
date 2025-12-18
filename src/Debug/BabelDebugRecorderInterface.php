<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Debug;

interface BabelDebugRecorderInterface
{
    /**
     * @param array<string,mixed> $event
     */
    public function record(string $type, array $event): void;

    /**
     * @return array{types: array<string,int>, events: list<array<string,mixed>>}
     */
    public function snapshot(): array;

    public function reset(): void;
}
