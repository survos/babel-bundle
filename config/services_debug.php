<?php
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\BabelBundle\DataCollector\BabelDataCollector;
use Survos\BabelBundle\Debug\BabelDebugRecorderInterface;
use Survos\BabelBundle\Debug\RequestBabelDebugRecorder;
use Survos\BabelBundle\EventListener\BabelDebugPostLoadListener;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(RequestBabelDebugRecorder::class);
    $services->alias(BabelDebugRecorderInterface::class, RequestBabelDebugRecorder::class);

    $services->set(BabelDebugPostLoadListener::class);

    $services->set(BabelDataCollector::class)
        ->public()
        ->tag('data_collector', [
            'id' => 'babel',
            'template' => '@SurvosBabel/profiler/babel.html.twig',
        ]);
};
