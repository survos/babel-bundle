<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Survos\BabelBundle\Contract\TranslatorInterface;
use Survos\BabelBundle\Runtime\BabelRuntime;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BabelRuntimeRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleContext $localeContext,
        private readonly ?TranslatorInterface $translator=null,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ KernelEvents::REQUEST => ['onKernelRequest', 64] ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        BabelRuntime::init(
            translator: $this->translator,
            locale:     $this->localeContext->get(),
            fallback:   $this->fallbackLocale
        );
    }
}
