<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensure LocaleContext follows the Request locale for web traffic.
 * Runs AFTER FrameworkBundle's LocaleListener has resolved the locale.
 */
final class BabelLocaleRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleContext $context,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // LocaleListener priority is 16; use a lower (later) priority so we see its result.
        return [KernelEvents::REQUEST => ['onKernelRequest', -64]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $req = $event->getRequest();
        // Prefer _locale attribute if present (from routing), else the resolved Request locale
        $loc = $req->attributes->get('_locale') ?? $req->getLocale();
        if (\is_string($loc) && $loc !== '') {
            try {
                $this->context->set($loc);
            } catch (\InvalidArgumentException $e) {
                // Unsupported locale -> keep current; log for visibility
                $this->logger?->debug('LocaleContext: unsupported request locale', [
                    'requested' => $loc,
                    'enabled'   => $this->context->getEnabled(),
                    'err'       => $e->getMessage(),
                ]);
            }
        }

    }
}
