<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Doctrine\ORM\Events;
use Survos\BabelBundle\DataCollector\BabelDataCollector;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelCarrierScanPass;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelTraitAwareScanPass;
use Survos\BabelBundle\EventListener\BabelPostLoadHydrator;
use Survos\BabelBundle\EventListener\StringBackedTranslatableFlushSubscriber;
use Survos\BabelBundle\Service\ExternalTranslatorBridge;
use Survos\BabelBundle\Service\TargetLocaleResolver;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosBabelBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SurvosBabelBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/src/Entity',
                        'prefix' => 'Survos\\BabelBundle\\Entity',
                        'alias' => 'Babel',
                    ],
                ],
            ],
        ]);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__).'/config/services.php');

        if (!$builder->hasParameter('survos_babel.scan_namespaces')) {
            $builder->setParameter('survos_babel.scan_namespaces', [
                'App\\Entity\\',
                'App\\Entity\\Translations\\',
            ]);
        }

        $builder->register(BabelDataCollector::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        if ((bool) $builder->getParameter('kernel.debug')) {
            $container->import(\dirname(__DIR__).'/config/services_debug.php');
        }

        $builder->register(TargetLocaleResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        if (!$builder->hasParameter('kernel.enabled_locales')) {
            $builder->setParameter('kernel.enabled_locales', []);
        }

        // postLoad hydration
        $builder->register(BabelPostLoadHydrator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('doctrine.event_listener', ['event' => Events::postLoad]);

        // IMPORTANT: register flush subscriber explicitly as listeners (reliable)
        $builder->register(StringBackedTranslatableFlushSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->setArgument('$debug', '%kernel.debug%')
            ->addTag('doctrine.event_listener', ['event' => Events::onFlush])
            ->addTag('doctrine.event_listener', ['event' => Events::postFlush]);

        $builder->register(ExternalTranslatorBridge::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->setArgument(
                '$manager',
                new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            );
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new BabelCarrierScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new BabelTraitAwareScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 49);
    }
}
