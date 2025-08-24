<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Survos\BabelBundle\Adapter\TranslatorAdapter;
use Survos\BabelBundle\Contract\TranslatorInterface;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelCarrierScanPass;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelTraitAwareScanPass;
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

        // Fallback namespaces the compiler passes can use if Doctrine mappings/params aren't available.
        if (!$builder->hasParameter('survos_babel.scan_namespaces')) {
            $builder->setParameter('survos_babel.scan_namespaces', [
                'App\\Entity\\',
                'App\\Entity\\Translations\\',
            ]);
        }

        $builder->register(ExternalTranslatorBridge::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->setArgument('$manager', new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $builder->register(TranslatorAdapter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->setArgument('$manager', new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $builder->setAlias(TranslatorInterface::class, TranslatorAdapter::class)->setPublic(false);
    }

    public function xxloadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__).'/config/services.php');

        $builder->register(ExternalTranslatorBridge::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            // Soft reference to Survos\TranslatorBundle\Service\TranslatorManager
            ->setArgument('$manager', new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        // register the adapter
        $builder->register(TranslatorAdapter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true)
            // Soft reference to TranslatorManager (null if bundle not installed)
            ->setArgument('$manager', new Reference('Survos\TranslatorBundle\Service\TranslatorManager', ContainerInterface::NULL_ON_INVALID_REFERENCE));

// alias the interface to the concrete adapter
        $builder->setAlias(TranslatorInterface::class, TranslatorAdapter::class)->setPublic(false);

    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Run scanners BEFORE optimization/removal so they can see definitions/params set by DoctrineBundle.
        $container->addCompilerPass(new BabelCarrierScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new BabelTraitAwareScanPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 49);
    }

}
