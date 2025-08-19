<?php
declare(strict_types=1);

namespace Survos\BabelBundle;

use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Command\BabelBrowseCommand;
use Survos\BabelBundle\Command\BabelPopulateCommand;
use Survos\BabelBundle\Command\BabelTranslateMissingCommand;
use Survos\BabelBundle\EventListener\TranslatableListener;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;
use Survos\LibreTranslateBundle\Service\LibreTranslateService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosBabelBundle extends AbstractBundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass($this);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->children()
            ->scalarNode('fallback_locale')->defaultValue('en')->info('Fallback when no translation exists')->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /* cool kids way
        array_map(fn($commandClass) => $builder->autowire($commandClass)
            ->setAutoconfigured(true)
            ->addTag('console.command'), [
            BabelPopulateCommand::class,
            BabelTranslateMissingCommand::class
        ]);
        */

        foreach ([
            BabelBrowseCommand::class,
                     BabelPopulateCommand::class,
                 ] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }
        $builder->autowire(BabelTranslateMissingCommand::class)
            ->setAutoconfigured(true)
            ->setArgument('$libreTranslateService', new Reference(LibreTranslateService::class))
            ->addTag('console.command');

        // Core store (uses the default EntityManager)
        foreach ([
                     TranslationStore::class,
                     LocaleContext::class
                 ] as $publicClass) {
            $builder->autowire($publicClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Doctrine subscriber:
        // - prePersist/preUpdate: compute hashes, ensure Str & source StrTranslation
        // - postLoad: replace #[Translatable] fields with current-locale text
//        $builder->autowire(TranslatableSubscriber::class)
//            ->setAutowired(true)
//            ->setAutoconfigured(true)
//            ->setPublic(false)
//            // You can override these in your app’s DI if needed
//            ->setArgument('$currentLocale', '%kernel.default_locale%')
//            ->setArgument('$fallbackLocale', $config['fallback_locale'])
//            // attach to Doctrine; by default it listens on the default connection/EM
//            ->addTag('doctrine.event_subscriber')
//        // If you have multiple connections/managers and want to bind explicitly, uncomment one:
//        // ->addTag('doctrine.event_subscriber', ['connection' => 'default'])
//            // so we don't conflict with pixie!
////         ->addTag('doctrine.event_subscriber', ['entity_manager' => 'default'])
//            ;
//\

        $builder->autowire(\Survos\BabelBundle\Validator\BabelLocaleValidator::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('validator.constraint_validator');

        $x = $builder->autowire(TranslatableListener::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
//            ->setArgument('$currentLocale', '%kernel.default_locale%')
//            ->setArgument('$fallbackLocale', $config['fallback_locale'])
//            // @todo: put the em or connection in the bundle config
            ->addTag('doctrine.event_listener', ['event' => 'preUpdate']);//            ->addTag('doctrine.event_subscriber', ['entity_manager' => 'default'])
        ;
//            ->addTag('doctrine.event_subscriber');
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TranslationStore::class)) {
            return;
        }

        $index = [];

        // Get EM names; fallback to "default" if parameter missing
        $emsParam = $container->hasParameter('doctrine.entity_managers')
            ? $container->getParameter('doctrine.entity_managers')
            : ['default'];

        // Normalize to a flat list of EM names (param can be a map or a list)
        $emNames = [];
        foreach ((array)$emsParam as $k => $v) {
            $emNames[] = is_numeric($k) ? (string)$v : (string)$k;
        }
        $emNames = $emNames ?: ['default'];

        foreach ($emNames as $em) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $em);

            if (!$container->hasDefinition($chainId) && !$container->hasAlias($chainId)) {
                // Try the default chain as a last resort
                $chainId = 'doctrine.orm.default_metadata_driver';
                if (!$container->hasDefinition($chainId) && !$container->hasAlias($chainId)) {
                    continue;
                }
            }

            $chainDef = $container->hasDefinition($chainId)
                ? $container->getDefinition($chainId)
                : $container->findDefinition($chainId);

            foreach ($chainDef->getMethodCalls() as [$method, $args]) {
                if ($method !== 'addDriver' || \count($args) < 2) {
                    continue;
                }

                /** @var Reference|string $driverRef */
                $driverRef = $args[0];
                $prefix = (string)$args[1];

                $driverId = (string)$driverRef;
                if (!$container->hasDefinition($driverId) && !$container->hasAlias($driverId)) {
                    continue;
                }

                $driverDef = $container->hasDefinition($driverId)
                    ? $container->getDefinition($driverId)
                    : $container->findDefinition($driverId);

                // Attribute driver usually has first argument = array of directories
                $pathsArg = $driverDef->getArgument(0) ?? [];
                $paths = (array)$container->getParameterBag()->resolveValue($pathsArg);

                foreach ($paths as $baseDir) {
                    if (!\is_string($baseDir) || !is_dir($baseDir)) {
                        continue;
                    }

                    $rii = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
                    );

                    /** @var \SplFileInfo $file */
                    foreach ($rii as $file) {
                        if (!$file->isFile() || $file->getExtension() !== 'php') {
                            continue;
                        }

                        // Build FQCN from prefix + relative path
                        $rel = substr($file->getPathname(), strlen($baseDir));
                        $rel = ltrim(str_replace(DIRECTORY_SEPARATOR, '\\', $rel), '\\');
                        $class = $prefix . '\\' . preg_replace('/\.php$/', '', $rel);

                        if (!class_exists($class)) {
                            continue;
                        }

                        $rc = new \ReflectionClass($class);
                        if ($rc->isAbstract()) {
                            continue;
                        }

                        // One optional #[BabelLocale] anywhere
                        $localeProp = null;
                        foreach ($rc->getProperties() as $p) {
                            if ($p->getAttributes(BabelLocale::class)) {
                                $localeProp = $p->getName();
                                break;
                            }
                        }

                        // Public #[Translatable] properties with optional context
                        $fields = [];
                        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                            $attrs = $p->getAttributes(Translatable::class);
                            if (!$attrs) {
                                continue;
                            }
                            /** @var Translatable $meta */
                            $meta = $attrs[0]->newInstance();
                            $fields[$p->getName()] = ['context' => $meta->context];
                        }

                        if (!$fields && $localeProp === null && !$rc->hasProperty('tCodes')) {
                            continue;
                        }

                        $index[$class] = [
                            'fields' => $fields,                 // name => ['context' => ?string]
                            'localeProp' => $localeProp,             // ?string
                            'hasTCodes' => $rc->hasProperty('tCodes'), // bool
                        ];
                    }
                }
            }
        }

        // Inject the precomputed index into the store
        if (!$index) {
            throw new \RuntimeException('survos_babel: empty translatable index');
        }
        // Use findDefinition so aliases are resolved
        $def = $container->findDefinition(TranslationStore::class);
        $def->setArgument('$translatableIndex', $index); // constructor arg

    }
}
