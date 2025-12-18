<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Debug;

use Symfony\Contracts\Service\ResetInterface;

final class RequestBabelDebugRecorder implements BabelDebugRecorderInterface, ResetInterface
{
    /** @var list<array<string,mixed>> */
    private array $events = [];

    /** @var array<string,int> */
    private array $types = [];

    public function record(string $type, array $event): void
    {
        $this->types[$type] = ($this->types[$type] ?? 0) + 1;

        $this->events[] = [
            'type' => $type,
            'ts' => (new \DateTimeImmutable())->format('H:i:s.v'),
            ...$event,
        ];
    }

    public function snapshot(): array
    {
        return [
            'types' => $this->types,
            'events' => $this->events,
        ];
    }

    public function reset(): void
    {
        $this->types = [];
        $this->events = [];
    }
}
