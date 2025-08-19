<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Command\BabelTranslateMissingCommand;
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
        foreach ([BabelTranslateMissingCommand::class] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command')
            ;
        }

        // Core store (uses the default EntityManager)
        $builder->autowire(TranslationStore::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        // Doctrine subscriber:
        // - prePersist/preUpdate: compute hashes, ensure Str & source StrTranslation
        // - postLoad: replace #[Translatable] fields with current-locale text
//        $builder->autowire(TranslatableSubscriber::class)
//            ->setAutowired(true)
//            ->setAutoconfigured(true)
//            ->setPublic(false)
//            // You can override these in your app’s DI if needed
//            ->setArgument('$currentLocale', '%kernel.default_locale%')
//            ->setArgument('$fallbackLocale', $config['fallback_locale'])
//            // attach to Doctrine; by default it listens on the default connection/EM
//            ->addTag('doctrine.event_subscriber')
//        // If you have multiple connections/managers and want to bind explicitly, uncomment one:
//        // ->addTag('doctrine.event_subscriber', ['connection' => 'default'])
//            // so we don't conflict with pixie!
////         ->addTag('doctrine.event_subscriber', ['entity_manager' => 'default'])
//            ;
//\

        $builder->autowire(\Survos\BabelBundle\Validator\BabelLocaleValidator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('validator.constraint_validator');


        // Remove the dd() from getSubscribedEvents() first!
        $x = $builder->autowire(TranslatableSubscriber::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArgument('$currentLocale', '%kernel.default_locale%')
            ->setArgument('$fallbackLocale', $config['fallback_locale'])
            // @todo: put the em or connection in the bundle config
            ->addTag('doctrine.event_subscriber', ['entity_manager' => 'default'])
            ;
//            ->addTag('doctrine.event_subscriber');

//        $services = $container->services();
//        $services
//            ->set(TranslatableSubscriber::class)
//            ->autowire()
//            ->autoconfigure()
//            ->arg('$currentLocale', '%kernel.default_locale%')
//            ->arg('$fallbackLocale', $config['fallback_locale'])
////            ->tag('doctrine.event_listener', ['event' => 'postFlush'])
//            ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
//            ->tag('doctrine.event_listener', ['event' => 'preRemove'])
//            ->tag('doctrine.event_listener', ['event' => 'prePersist'])
//            ->tag('doctrine.event_listener', ['event' => 'postPersist']);
//

    }
}
