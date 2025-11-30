<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $c): void {
    $c->extension('doctrine', [
        'dbal' => [
            'connections' => [
                'default' => [
                    'url' => 'sqlite:///:memory:',
                ],
            ],
        ],
        'orm' => [
            'default_entity_manager' => 'default',
            'entity_managers' => [
                'default' => [
                    'connection' => 'default',
                    'mappings' => [
                        'TestEntities' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => '%kernel.project_dir%/Entity',
                            'prefix' => 'Survos\\BabelBundle\\Tests\\App\\Entity',
                        ],
                        'BabelEntities' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => '%kernel.project_dir%/../../src/Entity',
                            'prefix' => 'Survos\\BabelBundle\\Entity',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $c->parameters()
        ->set('survos_babel.scan_entity_managers', ['default'])
        ->set('survos_babel.allowed_namespaces', ['Survos\\BabelBundle\\Tests\\App\\Entity']);
};
