<?php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $c): void {
    $c->extension('framework', [
        'secret' => 'S3CRET',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'test' => true,
        'cache' => [
            'app' => 'cache.adapter.array',
        ],
    ]);
};
