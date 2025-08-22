<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Scanner;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Attribute\Translatable;

/**
 * Builds a map of translatable fields per entity class.
 *
 * Output shape:
 * [
 *   App\Entity\Owner::class => ['label','description'],
 *   ...
 * ]
 */
final class TranslatableScanner
{
    public function __construct(
        private ManagerRegistry $doctrine,
        /** @var list<string> */ private array $scanEntityManagers = ['default'],
        /** @var list<string> */ private array $allowedNamespaces = ['App\\Entity', 'Survos\\PixieBundle\\Entity'],
    ) {}

    /** @return array<class-string, list<string>> */
    public function buildMap(): array
    {
        $map = [];

        foreach ($this->scanEntityManagers as $emName) {
            $em = $this->doctrine->getManager($emName);
            foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
                $class = $meta->getName();
                if (!$this->isAllowed($class)) continue;

                $ref = new \ReflectionClass($class);
                $attrs = $ref->getAttributes(BabelStorage::class);
                if (!$attrs) continue;

                $mode = $attrs[0]->newInstance()->mode;
                if ($mode !== StorageMode::Property) {
                    // We only map translatable fields for property-storage entities here.
                    continue;
                }

                $fields = $this->discoverFields($ref);
                if ($fields) {
                    $map[$class] = array_values(array_unique($fields));
                }
            }
        }

        ksort($map);
        return $map;
    }

    /** @return list<string> */
    private function discoverFields(\ReflectionClass $ref): array
    {
        // 1) Look for #[Translatable] on PUBLIC properties (preferred)
        $fields = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            foreach ($p->getAttributes(Translatable::class) as $a) {
                $name = $a->newInstance()->name ?? $p->getName();
                $fields[] = $name;
                break;
            }
        }
        if ($fields) return $fields;

        // 2) Fallback: call getTranslatableFields() if present (trait-style)
        if ($ref->hasMethod('getTranslatableFields')) {
            try {
                // Use a dummy instance if constructor has no required args; otherwise skip
                $ctor = $ref->getConstructor();
                $obj = ($ctor && $ctor->getNumberOfRequiredParameters() > 0) ? null : $ref->newInstance();
                if ($obj && \is_array($obj->getTranslatableFields())) {
                    /** @var list<string> $list */
                    $list = $obj->getTranslatableFields();
                    return $list;
                }
            } catch (\Throwable) {
                // ignore discovery errors
            }
        }
        return [];
    }

    private function isAllowed(string $fqcn): bool
    {
        foreach ($this->allowedNamespaces as $ns) {
            if (str_starts_with($fqcn, rtrim($ns, '\\').'\\')) return true;
        }
        return false;
    }
}
