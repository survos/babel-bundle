<?php
declare(strict_types=1);

namespace Survos\BabelBundle\DependencyInjection\Compiler;

use Survos\BabelBundle\Attribute\BabelLocale;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Scans configured source roots for classes using #[BabelStorage(Property)]
 * and indexes translatable fields (including those declared in traits).
 * Results are stored as container parameter 'survos_babel.translatable_index'.
 *
 * Configure roots via the 'survos_babel.scan_roots' parameter:
 *   parameters:
 *     survos_babel.scan_roots:
 *       '%kernel.project_dir%/src': 'App'
 *       '%kernel.project_dir%/packages/pixie-bundle/src': 'Survos\PixieBundle'
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

// Prefer constructor injection:
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
        if (!\str_ends_with($rel, '.php')) return null;
        return $prefix . '\\' . \str_replace('/', '\\', \substr($rel, 0, -4));
    }

    private function maybeIndexClass(array &$index, string $fqcn): void
    {
        try { $rc = new \ReflectionClass($fqcn); } catch (\Throwable) { return; }
        if ($rc->isAbstract() || $rc->isTrait()) return;

        $storageAttr = $rc->getAttributes(\Survos\BabelBundle\Attribute\BabelStorage::class)[0] ?? null;
        if (!$storageAttr || $storageAttr->newInstance()->mode !== \Survos\BabelBundle\Attribute\StorageMode::Property) {
            return;
        }

        $props = $this->collectPropsRecursive($rc);

        // Translatable fields
        $fields = [];
        foreach ($props as $entry) {
            $p = $entry['prop'];
            $attrs = $p->getAttributes(Translatable::class);
            if (!$attrs) continue;
            $meta = $attrs[0]->newInstance();
            $fields[$p->getName()] = ['context' => $meta->name ?? null];
        }

        // Locale accessor via #[BabelLocale] on property or method
        $localeAccessor = null;

        // properties first
        foreach ($props as $entry) {
            $p = $entry['prop'];
            $attrs = $p->getAttributes(BabelLocale::class);
            if ($attrs) {
                $fmt = $attrs[0]->newInstance()->format ?? null;
                $localeAccessor = ['type' => 'prop', 'name' => $p->getName(), 'format' => $fmt];
                break;
            }
        }
        // methods next (on this class only; not traits)
        if (!$localeAccessor) {
            foreach ($rc->getMethods() as $m) {
                $attrs = $m->getAttributes(BabelLocale::class);
                if ($attrs) {
                    $fmt = $attrs[0]->newInstance()->format ?? null;
                    $localeAccessor = ['type' => 'method', 'name' => $m->getName(), 'format' => $fmt];
                    break;
                }
            }
        }
        // fallback heuristic
        if (!$localeAccessor) {
            foreach (['srcLocale', 'sourceLocale'] as $n) {
                if ($rc->hasProperty($n)) {
                    $localeAccessor = ['type' => 'prop', 'name' => $n, 'format' => null];
                    break;
                }
            }
        }

        if (!$fields && !$localeAccessor) return;

        $index[$fqcn] = [
            'fields'         => $fields,
            'localeAccessor' => $localeAccessor,
            'hasTCodes'      => false,
        ];
    }


    /** @return array<array{name:string, prop:\ReflectionProperty}> */
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

    /** @return \ReflectionClass[] */
    private function collectTraitsRecursive(\ReflectionClass $rc): array
    {
        $seen = []; $out = []; $stack = $rc->getTraits();
        while ($stack) {
            $t = \array_pop($stack);
            $name = $t->getName();
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $out[] = $t;
            foreach ($t->getTraits() as $tt) $stack[] = $tt;
        }
        return $out;
    }

    private function classUsesTraitRecursive(\ReflectionClass $rc, string $traitFqcn): bool
    {
        foreach ($rc->getTraitNames() as $t) if ($t === $traitFqcn) return true;
        foreach ($rc->getTraits() as $tRc) if ($this->classUsesTraitRecursive($tRc, $traitFqcn)) return true;
        return ($parent = $rc->getParentClass()) ? $this->classUsesTraitRecursive($parent, $traitFqcn) : false;
    }
}
