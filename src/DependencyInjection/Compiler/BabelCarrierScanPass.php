<?php
declare(strict_types=1);

namespace Survos\BabelBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BabelCarrierScanPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setParameter('survos_babel.scan_entity_managers',
            $container->hasParameter('survos_babel.scan_entity_managers')
                ? $container->getParameter('survos_babel.scan_entity_managers')
                : ['default']
        );

        $container->setParameter('survos_babel.allowed_namespaces',
            $container->hasParameter('survos_babel.allowed_namespaces')
                ? $container->getParameter('survos_babel.allowed_namespaces')
                : ['App\\Entity', 'Survos\\PixieBundle\\Entity']
        );
    }
}
