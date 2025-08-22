<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Survos\BabelBundle\SurvosBabelBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new SurvosBabelBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/../Fixtures/config/bundles/bundles.php');
        $loader->load(__DIR__.'/../Fixtures/config/packages/framework.php');
        $loader->load(__DIR__.'/../Fixtures/config/packages/doctrine.php');
        $loader->load(__DIR__.'/../Fixtures/config/packages/services_test.php');
    }

    public function getProjectDir(): string
    {
        // simulate a project dir at the bundle root for %kernel.project_dir%
        return \dirname(__DIR__, 2);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Nothing custom here; SurvosBabelBundle adds its compiler pass/services itself.
    }
}
