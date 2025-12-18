<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Debug;

final class NullBabelDebugRecorder implements BabelDebugRecorderInterface
{
    public function record(string $type, array $event): void {}
    public function snapshot(): array { return ['types' => [], 'events' => []]; }
    public function reset(): void {}
}
