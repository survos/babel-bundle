<?php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Survos\BabelBundle\Contract\TranslatorInterface;
use Survos\BabelBundle\Tests\Fixtures\Service\FakeTranslator;

return static function (ContainerConfigurator $c): void {
    $s = $c->services()->defaults()->autowire()->autoconfigure();

    // Register a concrete fake translator and alias the interface to it
    $s->set(FakeTranslator::class)->public();
    $s->alias(TranslatorInterface::class, FakeTranslator::class)->public();
};
