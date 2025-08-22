<?php
declare(strict_types=1);

namespace Survos\BabelBundle\DependencyInjection\Compiler;

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
        try {
            $rc = new \ReflectionClass($fqcn);
        } catch (\Throwable) {
            return;
        }
        if ($rc->isAbstract() || $rc->isTrait()) {
            return;
        }

        // Only consider classes explicitly tagged for property storage
        $storageAttr = $rc->getAttributes(BabelStorage::class)[0] ?? null;
        if (!$storageAttr || $storageAttr->newInstance()->mode !== StorageMode::Property) {
            return;
        }

        $props = $this->collectPropsRecursive($rc);

        // Fields with #[Translatable]
        $fields = [];
        foreach ($props as $entry) {
            $p = $entry['prop'];
            $attrs = $p->getAttributes(Translatable::class);
            if (!$attrs) continue;
            $meta = $attrs[0]->newInstance();
            $fields[$p->getName()] = ['context' => $meta->name ?? null];
        }

        // Locale prop (optional)
        $localeProp = null;
        foreach ($props as $entry) {
            $n = $entry['prop']->getName();
            if ($n === 'srcLocale' || $n === 'sourceLocale') { $localeProp = $n; break; }
        }

        // Heuristic: needs hooks?
        $usesHooksTrait =
            $this->classUsesTraitRecursive($rc, 'Survos\\BabelBundle\\Traits\\BabelTranslatableAttrTrait') ||
            $this->classUsesTraitRecursive($rc, 'Survos\\BabelBundle\\Traits\\BabelTranslatableTrait');

        $allPropNames = [];
        foreach ($props as $e) { $allPropNames[$e['prop']->getName()] = true; }

        $needsHooks = false; $fieldsNeedingHooks = [];
        foreach (\array_keys($fields) as $fname) {
            $backing1 = $fname . '_raw';
            $backing2 = $fname . 'Backing';
            if (!isset($allPropNames[$backing1]) && !isset($allPropNames[$backing2]) && !$usesHooksTrait) {
                $needsHooks = true; $fieldsNeedingHooks[] = $fname;
            }
        }

        if (!$fields && !$localeProp) return;

        $index[$fqcn] = [
            'fields'             => $fields,
            'localeProp'         => $localeProp,
            'hasTCodes'          => false,
            'needsHooks'         => $needsHooks,
            'fieldsNeedingHooks' => $fieldsNeedingHooks,
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
