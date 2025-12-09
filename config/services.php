<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\BabelBundle\Cache\TranslatableMapWarmer;
use Survos\BabelBundle\Command\BabelBrowseCommand;
use Survos\BabelBundle\Command\BabelDebugSchemaCommand;
use Survos\BabelBundle\Command\BabelPreviewCommand;
use Survos\BabelBundle\Command\BabelStatsCommand;
use Survos\BabelBundle\Command\BabelTranslatableDumpCommand;
use Survos\BabelBundle\Command\CarriersListCommand;
use Survos\BabelBundle\Command\TranslatableIndexCommand;
use Survos\BabelBundle\Command\TranslateCommand;
use Survos\BabelBundle\Command\TranslationsEnsureCommand;
use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\StringResolver;
use Survos\BabelBundle\Service\StringStorageRouter;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Service\TranslatableMapProvider;
use Survos\LibreTranslateBundle\Service\TranslationClientService;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    $s->defaults()->autowire(true)->autoconfigure(true)->public(false);

    // Load ONCE; avoid reloading which can overwrite explicit args with plain autowiring
    $s->load('Survos\\BabelBundle\\', \dirname(__DIR__) . '/src/')
        ->exclude([
            \dirname(__DIR__) . '/src/Entity/',
            \dirname(__DIR__) . '/src/Traits/',
            \dirname(__DIR__) . '/src/Resources/',
            \dirname(__DIR__) . '/src/Tests/',
            \dirname(__DIR__) . '/src/Event/',
            \dirname(__DIR__) . '/src/Kernel.php',
//            __DIR__ . '/../src/DependencyInjection/',
        ]);

    $s->load('Survos\\BabelBundle\\EventListener\\', __DIR__ . '/../src/EventListener/');
    $s->load('Survos\\BabelBundle\\EventSubscriber\\', __DIR__ . '/../src/EventSubscriber/'); // if any


    // Engines
    $s->set(CodeStorage::class)
        ->autowire(true)
        ->arg('$registry', service('doctrine'))
    ;

    $s->set(LocaleContext::class)
        ->autowire(true)
        ->autoconfigure(true)
        ->public()
        ->arg('$requests', service('request_stack'))
    ;


    // Router: EXPLICIT constructor args so we never rely on interface autowiring
    $s->set(StringStorageRouter::class)
        ->arg('$code', service(CodeStorage::class))
        ->arg('$property', service(PropertyStorage::class))
        ->public();

    // Other services
    $s->set(StringResolver::class)
        ->arg('$registry', service('doctrine'))
        ->public();

    $s->set(CarrierRegistry::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    $s->set(TranslatableIndex::class)
        ->arg('$map', param('survos_babel.translatable_index'))
        ->public();

    // Commands

    foreach ([BabelBrowseCommand::class,
                 BabelDebugSchemaCommand::class,
                 BabelTranslatableDumpCommand::class] as $commandClass) {
        $s->set($commandClass)
            ->public()
            ->autoconfigure(true)
            ->tag('console.command');

    }
    $s->set(TranslationsEnsureCommand::class)
        ->autoconfigure()
//        ->arg('$registry', service('doctrine'))
//        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(BabelStatsCommand::class)
        ->autoconfigure()
//        ->arg('$registry', service('doctrine'))
//        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(BabelPreviewCommand::class)
        ->autoconfigure()
//        ->arg('$registry', service('doctrine'))
//        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(TranslateCommand::class)
        ->autoconfigure()
//        ->arg('$registry', service('doctrine'))
//        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(CarriersListCommand::class)
        ->arg('$registry', service(CarrierRegistry::class))
        ->tag('console.command');

    $s->set(TranslatableIndexCommand::class)
        ->arg('$index', service(TranslatableIndex::class))
        ->tag('console.command');

    // Scanner + cache warmer
    $s->set(TranslatableScanner::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    $s->set(TranslatableMapWarmer::class)
        ->arg('$scanner', service(TranslatableScanner::class))
        ->arg('$cachePool', service('cache.app'))
        ->tag('kernel.cache_warmer');

    $s->set(TranslatableMapProvider::class)
        ->arg('$cachePool', service('cache.app'));
};
