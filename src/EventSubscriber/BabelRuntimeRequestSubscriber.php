<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Survos\BabelBundle\Runtime\BabelRuntime;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BabelRuntimeRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleContext $localeContext,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Must run after routing AND after whatever app-level locale subscriber resolves the
        // final request locale (LocaleContext::get() lazily resolves and caches on first call --
        // priority 64 ran before Symfony's own LocaleListener/LocaleAwareListener (16/15) and any
        // app subscriber sitting between them, so it locked in a stale locale before the real one
        // was ever set). Low priority here means "run last".
        return [ KernelEvents::REQUEST => ['onKernelRequest', 8] ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        BabelRuntime::init(
            locale:     $this->localeContext->get(),
            fallback:   $this->fallbackLocale
        );
    }
}
