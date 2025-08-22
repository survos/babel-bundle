<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;
use Survos\BabelBundle\Service\StringStorageRouter;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    // Defaults: keep it easy
    $s->defaults()
        ->autowire(true)
        ->autoconfigure(true)   // <-- this lets Doctrine auto-tag ServiceEntityRepository, and AsCommand auto-register
        ->public(false);

    // Load almost everything in src/, but skip entities so they don't become services
    $s->load('Survos\\BabelBundle\\', \dirname(__DIR__).'/src/')
        ->exclude([
            \dirname(__DIR__).'/src/Entity/',
        ]);

    // A few services need explicit args/bindings:

    // Router depends on our two engines explicitly (autowire would work too, but be explicit)
    $s->set(StringStorageRouter::class)
        ->arg('$codeEngine', service(CodeStorage::class))
        ->arg('$propertyEngine', service(PropertyStorage::class))
        ->public();

    // CarrierRegistry needs parameters from the compiler pass
    $s->set(CarrierRegistry::class)
        ->arg('$doctrine', service('doctrine'))
        ->arg('$scanEntityManagers', param('survos_babel.scan_entity_managers'))
        ->arg('$allowedNamespaces', param('survos_babel.allowed_namespaces'))
        ->public();

    // (Nothing else strictly needs to be public; commands are discovered via #[AsCommand])
};
