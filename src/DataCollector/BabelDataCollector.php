<?php
declare(strict_types=1);

namespace Survos\BabelBundle\DataCollector;

use Survos\BabelBundle\Debug\BabelDebugRecorderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class BabelDataCollector extends DataCollector
{
    public function __construct(
        private readonly BabelDebugRecorderInterface $recorder,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $snap = $this->recorder->snapshot();

        $this->data = [
            'request_locale' => $request->getLocale(),
            'default_locale' => $request->getDefaultLocale(),
            'types' => $snap['types'],
            'events' => $snap['events'],
        ];

        // Critical: reset only after snapshot so we never lose events to reset timing.
        $this->recorder->reset();
    }

    public function getName(): string
    {
        return 'babel';
    }

    /** @return array<string,int> */
    public function types(): array
    {
        return $this->data['types'] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function events(): array
    {
        return $this->data['events'] ?? [];
    }

    public function requestLocale(): string
    {
        return (string) ($this->data['request_locale'] ?? '');
    }

    public function defaultLocale(): string
    {
        return (string) ($this->data['default_locale'] ?? '');
    }
}
