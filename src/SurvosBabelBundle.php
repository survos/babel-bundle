<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\EventSubscriber\TranslatableSubscriber;
use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosBabelBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->children()
                ->scalarNode('fallback_locale')->defaultValue('en')->info('Fallback when no translation exists')->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Services
        $builder->register(TranslationStore::class)
            ->setAutowired(true)->setAutoconfigured(true)->setPublic(false);

        // Doctrine subscriber (reads #[Translatable] properties and replaces with translated text)
        $builder->register(TranslatableSubscriber::class)
            ->setAutowired(true)->setAutoconfigured(true)->setPublic(false)
            // inject current locale from kernel.request (Request/Locale) is app-specific;
            // default to kernel.default_locale (override in app if needed).
            ->setArgument('$currentLocale', '%kernel.default_locale%')
            ->setArgument('$fallbackLocale', $config['fallback_locale'])
            ->addTag('doctrine.event_subscriber');
    }
}
