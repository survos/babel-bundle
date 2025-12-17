<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Doctrine\ORM\Events;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelCarrierScanPass;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelTraitAwareScanPass;
use Survos\BabelBundle\EventListener\BabelPostLoadHydrator;
use Survos\BabelBundle\EventListener\StringBackedTranslatableFlushSubscriber;
//use Survos\BabelBundle\EventSubscriber\BabelLocaleRequestSubscriber;
//use Survos\BabelBundle\Service\StringResolver;
//use Survos\CodeBundle\Service\DirectEntityTranslatableUpdater;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Survos\BabelBundle\Service\ExternalTranslatorBridge;

final class SurvosBabelBundle extends AbstractBundle
{

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__).'/config/services.php');

        // old, hopefully deletable!
//        foreach ([StringResolver::class, BabelLocaleRequestSubscriber::class] as $class) {
//            $builder->register($class)
//                ->setAutowired(true)
//                ->setAutoconfigured(true)
//                ->setPublic(true);
//        }

        // Fallback namespaces the compiler passes can use if Doctrine mappings/params aren't available.
        if (!$builder->hasParameter('survos_babel.scan_namespaces')) {
            $builder->setParameter('survos_babel.scan_namespaces', [
                'App\\Entity\\',
                'App\\Entity\\Translations\\',
            ]);
        }

        // --- Explicitly register Doctrine listeners (don’t rely on attributes/autodiscovery) ---
        $builder->register(BabelPostLoadHydrator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('doctrine.event_listener', ['event' => Events::postLoad]);

        // ensure a safe fallback if the framework param isn't defined
        if (!$builder->hasParameter('kernel.enabled_locales')) {
            $builder->setParameter('kernel.enabled_locales', []); // or ['en']
        }

        $builder->register(BabelPostLoadHydrator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('doctrine.event_listener', ['event' => Events::postLoad]);

        // ensure a safe fallback if the framework param isn't defined
        if (!$builder->hasParameter('kernel.enabled_locales')) {
            $builder->setParameter('kernel.enabled_locales', []);
        }

        // Use doctrine.event_subscriber so Doctrine calls getSubscribedEvents()
        $builder->register(StringBackedTranslatableFlushSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('doctrine.event_subscriber');

        $builder->register(StringBackedTranslatableFlushSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
//            ->setArgument('$enabledLocales', '%kernel.enabled_locales%')
//            ->addTag('doctrine.event_listener', ['event' => Events::prePersist])
//            ->addTag('doctrine.event_listener', ['event' => Events::preUpdate])
            ->addTag('doctrine.event_listener', ['event' => Events::onFlush])
            ->addTag('doctrine.event_listener', ['event' => Events::postFlush]);

        // Optional, but keep: soft engine bridge
        $builder->register(ExternalTranslatorBridge::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->setArgument('$manager', new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE));

    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Run scanners BEFORE optimization/removal so they can see definitions/params set by DoctrineBundle.
        $container->addCompilerPass(new BabelCarrierScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new BabelTraitAwareScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 49);
    }

}
