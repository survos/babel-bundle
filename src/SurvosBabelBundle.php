<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Survos\BabelBundle\DependencyInjection\Compiler\BabelCarrierScanPass;
use Survos\BabelBundle\DependencyInjection\Compiler\BabelTraitAwareScanPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosBabelBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__).'/config/services.php');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Parameters-only pass (sets EM/namespaces defaults)
        $container->addCompilerPass(new BabelCarrierScanPass());

        // Trait-aware scan pass (indexes #[Translatable] fields for property-mode entities)
        $container->addCompilerPass(new BabelTraitAwareScanPass());
    }
}
