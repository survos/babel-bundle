<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\BabelBundle\Command\CarriersListCommand;
use Survos\BabelBundle\Command\PopulateMissingCommand;
use Survos\BabelBundle\Command\TranslatableIndexCommand;
use Survos\BabelBundle\Command\TranslateCommand;
use Survos\BabelBundle\Contract\TranslatorInterface;
use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;
use Survos\BabelBundle\Service\StringCodeGenerator;
use Survos\BabelBundle\Service\StringResolver;
use Survos\BabelBundle\Service\StringStorageRouter;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\LibreTranslateBundle\Service\TranslationClientService;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    $s->defaults()->autowire(true)->autoconfigure(true)->public(false);

    // Exclude Entity *and* Traits to avoid "expected class" errors on traits
    $s->load('Survos\\BabelBundle\\', \dirname(__DIR__).'/src/')
        ->exclude([
            \dirname(__DIR__).'/src/Entity/',
            \dirname(__DIR__).'/src/Traits/',
        ]);

    // Bind our translator interface to the real adapter in prod
    $s->alias(TranslatorInterface::class, TranslationClientService::class)->public();

    $s->set(StringCodeGenerator::class)->public();

    $s->set(StringResolver::class)
        ->arg('$registry', service('doctrine'))
        ->public();

    $s->set(CodeStorage::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$translator', service(TranslatorInterface::class));

    $s->set(PropertyStorage::class)
        ->arg('$translator', service(TranslatorInterface::class));

    $s->set(StringStorageRouter::class)
        ->arg('$codeEngine', service(CodeStorage::class))
        ->arg('$propertyEngine', service(PropertyStorage::class))
        ->public();

    $s->set(CarrierRegistry::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    $s->set(TranslatableIndex::class)
        ->arg('$index', param('survos_babel.translatable_index'))
        ->public();

    $s->set(PopulateMissingCommand::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(TranslateCommand::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command');

    $s->set(CarriersListCommand::class)
        ->arg('$registry', service(CarrierRegistry::class))
        ->tag('console.command');

    $s->set(TranslatableIndexCommand::class)
        ->arg('$index', service(TranslatableIndex::class))
        ->tag('console.command');
};
<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\BabelBundle\Cache\TranslatableMapWarmer;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\TranslatableMapProvider;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    // keep defaults: autowire+autoconfigure
    $s->defaults()->autowire(true)->autoconfigure(true)->public(false);

    // make sure we load everything except entities
    $s->load('Survos\\BabelBundle\\', \dirname(__DIR__).'/src/')
        ->exclude([\dirname(__DIR__).'/src/Entity/']);

    // Scanner uses compiler-pass parameters
    $s->set(TranslatableScanner::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    // Cache warmer (cache.app is PSR-6)
    $s->set(TranslatableMapWarmer::class)
        ->arg('$scanner', service(TranslatableScanner::class))
        ->arg('$cachePool', service('cache.app'))
        ->tag('kernel.cache_warmer');

    // Provider
    $s->set(TranslatableMapProvider::class)
        ->arg('$cachePool', service('cache.app'));
};
