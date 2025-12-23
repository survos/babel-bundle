<?php
declare(strict_types=1);

namespace Survos\BabelBundle\DependencyInjection\Compiler;

use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\BabelTerm;
use Survos\BabelBundle\Attribute\Translatable;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

/**
 * Scans configured source roots for classes using #[BabelStorage(Property)]
 * and indexes translatable fields (including those declared in traits).
 * Results are stored as container parameter 'survos_babel.translatable_index'.
 *
 * Map shape (per FQCN):
 *   [
 *     'fields'         => [ fieldName => ['context' => ?string], ... ],
 *     'terms'          => [ fieldName => ['set'=>string,'multiple'=>bool,'context'=>?string], ... ],
 *     'localeAccessor' => ['type'=>'prop'|'method','name'=>string,'format'=>?string] | null,
 *     'sourceLocale'   => ?string,
 *     'targetLocales'  => ?array<string>,
 *     'hasTCodes'      => bool,
 *   ]
 */
final class BabelTraitAwareScanPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $rootsParam = $container->hasParameter('survos_babel.scan_roots')
            ? (array) $container->getParameter('survos_babel.scan_roots')
            : [];

        if (!$rootsParam) {
            $rootsParam = [
                (string) $container->getParameter('kernel.project_dir') . '/src' => 'App',
            ];
        }

        $index = [];

        foreach ($rootsParam as $dir => $prefix) {
            if (!\is_dir($dir)) {
                continue;
            }

            foreach ($this->scanPhpFiles($dir) as $file) {
                $fqcn = $this->classFromFile($file, $dir, $prefix);
                if (!$fqcn || !\class_exists($fqcn)) {
                    continue;
                }

                $this->maybeIndexClass($index, $fqcn);
            }
        }

        \ksort($index);
        $container->setParameter('survos_babel.translatable_index', $index);

        if ($container->hasDefinition(\Survos\BabelBundle\Service\TranslatableIndex::class)) {
            $def = $container->getDefinition(\Survos\BabelBundle\Service\TranslatableIndex::class);
            $def->setArgument('$map', $index);
        }
    }

    /** @return iterable<string> */
    private function scanPhpFiles(string $baseDir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }

    private function classFromFile(string $file, string $baseDir, string $prefix): ?string
    {
        $rel = \ltrim(\str_replace('\\', '/', \substr($file, \strlen($baseDir))), '/');
        if (!\str_ends_with($rel, '.php')) {
            return null;
        }

        return $prefix . '\\' . \str_replace('/', '\\', \substr($rel, 0, -4));
    }

    /**
     * @param array<string, array> $index
     */
    private function maybeIndexClass(array &$index, string $fqcn): void
    {
        try {
            $rc = new \ReflectionClass($fqcn);
        } catch (\Throwable) {
            return;
        }

        if ($rc->isAbstract() || $rc->isTrait()) {
            return;
        }

        $storageAttr = $rc->getAttributes(BabelStorage::class)[0] ?? null;
        if (!$storageAttr) {
            return;
        }

        if (!$rc->implementsInterface(BabelHooksInterface::class)) {
            throw new \LogicException(sprintf(
                'Class %s has #[%s] but does not implement %s. Add "implements %s" (and typically "use %s").',
                $fqcn,
                BabelStorage::class,
                BabelHooksInterface::class,
                BabelHooksInterface::class,
                BabelHooksTrait::class
            ));
        }

        if (!$this->classUsesTraitRecursive($rc, BabelHooksTrait::class)) {
            throw new \LogicException(sprintf(
                'Class %s implements %s but does not use %s. Add "use %s" to the entity.',
                $fqcn,
                BabelHooksInterface::class,
                BabelHooksTrait::class,
                BabelHooksTrait::class
            ));
        }

        $props = $this->collectPropsRecursive($rc);

        // 1) Translatable string fields
        $fields = [];
        foreach ($props as $entry) {
            $p     = $entry['prop'];
            $attrs = $p->getAttributes(Translatable::class);
            if (!$attrs) {
                continue;
            }
            $meta = $attrs[0]->newInstance();
            $fields[$p->getName()] = [
                'context' => $meta->context ?? null,
            ];
        }

        // 1b) Term fields
        $terms = [];
        foreach ($props as $entry) {
            $p     = $entry['prop'];
            $attrs = $p->getAttributes(BabelTerm::class);
            if (!$attrs) {
                continue;
            }
            /** @var BabelTerm $meta */
            $meta = $attrs[0]->newInstance();
            $terms[$p->getName()] = [
                'set'      => $meta->set,
                'multiple' => $meta->multiple,
                'context'  => $meta->context,
            ];
        }

        // 2) Locale config
        $localeAccessor = null;
        $sourceLocale   = null;
        $targetLocales  = null;

        $classAttrs = $rc->getAttributes(BabelLocale::class);
        if ($classAttrs !== []) {
            /** @var BabelLocale $meta */
            $meta          = $classAttrs[0]->newInstance();
            $sourceLocale  = $meta->locale ?: null;
            $targetLocales = $meta->targetLocales;
        }

        if ($localeAccessor === null) {
            foreach ($props as $entry) {
                $p     = $entry['prop'];
                $attrs = $p->getAttributes(BabelLocale::class);
                if (!$attrs) {
                    continue;
                }

                /** @var BabelLocale $meta */
                $meta = $attrs[0]->newInstance();

                $localeAccessor = [
                    'type'   => 'prop',
                    'name'   => $p->getName(),
                    'format' => $meta->format ?? null,
                ];

                if ($meta->locale) {
                    $sourceLocale = $meta->locale;
                }
                if ($meta->targetLocales !== null) {
                    $targetLocales = $meta->targetLocales;
                }

                break;
            }
        }

        if ($localeAccessor === null) {
            foreach ($rc->getMethods() as $m) {
                $attrs = $m->getAttributes(BabelLocale::class);
                if (!$attrs) {
                    continue;
                }

                /** @var BabelLocale $meta */
                $meta = $attrs[0]->newInstance();

                $localeAccessor = [
                    'type'   => 'method',
                    'name'   => $m->getName(),
                    'format' => $meta->format ?? null,
                ];

                if ($meta->locale) {
                    $sourceLocale = $meta->locale;
                }
                if ($meta->targetLocales !== null) {
                    $targetLocales = $meta->targetLocales;
                }

                break;
            }
        }

        if ($localeAccessor === null) {
            foreach (['srcLocale', 'sourceLocale'] as $n) {
                if ($rc->hasProperty($n)) {
                    $localeAccessor = [
                        'type'   => 'prop',
                        'name'   => $n,
                        'format' => null,
                    ];
                    break;
                }
            }
        }

        if ($fields === [] && $terms === [] && $localeAccessor === null && $sourceLocale === null && $targetLocales === null) {
            return;
        }

        $index[$fqcn] = [
            'fields'         => $fields,
            'terms'          => $terms,
            'localeAccessor' => $localeAccessor,
            'sourceLocale'   => $sourceLocale,
            'targetLocales'  => $targetLocales,
            'hasTCodes'      => false,
        ];
    }

    /**
     * @return array<array{name:string, prop:\ReflectionProperty}>
     */
    private function collectPropsRecursive(\ReflectionClass $rc): array
    {
        $out = [];
        foreach ($rc->getProperties() as $p) {
            $out[] = ['name' => $p->getName(), 'prop' => $p];
        }

        foreach ($this->collectTraitsRecursive($rc) as $t) {
            foreach ($t->getProperties() as $p) {
                $out[] = ['name' => $p->getName(), 'prop' => $p];
            }
        }

        if ($parent = $rc->getParentClass()) {
            $out = \array_merge($out, $this->collectPropsRecursive($parent));
        }

        return $out;
    }

    /**
     * @return \ReflectionClass[]
     */
    private function collectTraitsRecursive(\ReflectionClass $rc): array
    {
        $seen  = [];
        $out   = [];
        $stack = $rc->getTraits();

        while ($stack) {
            $t    = \array_pop($stack);
            $name = $t->getName();

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $out[]       = $t;

            foreach ($t->getTraits() as $tt) {
                $stack[] = $tt;
            }
        }

        return $out;
    }

    private function classUsesTraitRecursive(\ReflectionClass $rc, string $traitFqcn): bool
    {
        foreach ($rc->getTraitNames() as $t) {
            if ($t === $traitFqcn) {
                return true;
            }
        }

        foreach ($rc->getTraits() as $tRc) {
            if ($this->classUsesTraitRecursive($tRc, $traitFqcn)) {
                return true;
            }
        }

        return ($parent = $rc->getParentClass())
            ? $this->classUsesTraitRecursive($parent, $traitFqcn)
            : false;
    }
}
