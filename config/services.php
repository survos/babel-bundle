<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Command\PopulateMissingCommand;
use Survos\BabelBundle\Command\TranslateCommand;
use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;
use Survos\BabelBundle\Service\Engine\StringStorage;
use Survos\BabelBundle\Service\StringCodeGenerator;
use Survos\BabelBundle\Service\StringResolver;
use Survos\BabelBundle\Service\StringStorageRouter;
use Survos\LibreTranslateBundle\Service\TranslationClientService;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    // Core services (explicit, public for easy reuse in other bundles/commands)
    $s->set(StringCodeGenerator::class)->public();

    $s->set(StringResolver::class)
        ->arg('$registry', service('doctrine'))
        ->public();

    // Engines
    $s->set(CodeStorage::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$translator', service(TranslationClientService::class))
        ->public();

    $s->set(PropertyStorage::class)
        ->arg('$translator', service(TranslationClientService::class))
        ->public();

    // Router
    $s->set(StringStorageRouter::class)
        ->arg('$codeEngine', service(CodeStorage::class))
        ->arg('$propertyEngine', service(PropertyStorage::class))
        ->public();

    // Runtime carrier discovery (uses compiler-pass parameters)
    $s->set(CarrierRegistry::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    // Console commands
    $s->set(PopulateMissingCommand::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command')
        ->public();

    $s->set(TranslateCommand::class)
        ->arg('$registry', service('doctrine'))
        ->arg('$router', service(StringStorageRouter::class))
        ->tag('console.command')
        ->public();
};
