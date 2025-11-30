<?php
// File: tests/App/Kernel.php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\App;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Survos\BabelBundle\SurvosBabelBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

class Kernel extends BaseKernel

{
    use MicroKernelTrait;
    final public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new SurvosBabelBundle(),
        ];
    }

    final public function getProjectDir(): string
    {
        // simulate a project dir at the bundle root for %kernel.project_dir%
        return __DIR__;
    }

}
