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
            'auto_generate_proxy_classes' => true,
            'default_entity_manager' => 'default',
            'entity_managers' => [
                'default' => [
                    'connection' => 'default',
                    'mappings' => [
                        // map test fixture entity
                        'TestEntities' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => '%kernel.project_dir%/tests/Fixtures/Entity',
                            'prefix' => 'Survos\\BabelBundle\\Tests\\Fixtures\\Entity',
                        ],
                        // map Babel's Str/StrTranslation too (not strictly needed for property-mode tests)
                        'BabelEntities' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => '%kernel.project_dir%/src/Entity',
                            'prefix' => 'Survos\\BabelBundle\\Entity',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    // Tell the compiler pass/scanner to include our fixtures namespace
    $c->parameters()
        ->set('survos_babel.scan_entity_managers', ['default'])
        ->set('survos_babel.allowed_namespaces', ['Survos\\BabelBundle\\Tests\\Fixtures\\Entity']);
};
