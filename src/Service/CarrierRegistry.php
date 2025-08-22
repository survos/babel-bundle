<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;

/**
 * Discovers carriers (entities annotated with #[BabelStorage]) at runtime.
 */
final class CarrierRegistry
{
    public function __construct(
        private ManagerRegistry $doctrine,
        /** @var list<string> */
        private array $scanEntityManagers = ['default'],
        /** @var list<string> */
        private array $allowedNamespaces = ['App\\Entity','Survos\\PixieBundle\\Entity'],
    ) {}

    /**
     * @return array{code:list<class-string>, property:list<class-string>}
     */
    public function listCarriers(): array
    {
        $code = [];
        $prop = [];

        foreach ($this->scanEntityManagers as $emName) {
            $em = $this->doctrine->getManager($emName);
            foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
                $class = $meta->getName();
                if (!$this->isAllowed($class)) {
                    continue;
                }

                $ref = new \ReflectionClass($class);
                $attrs = $ref->getAttributes(BabelStorage::class);
                if (!$attrs) {
                    continue;
                }

                $mode = $attrs[0]->newInstance()->mode;
                if ($mode === StorageMode::Code) {
                    $code[] = $class;
                } else {
                    $prop[] = $class;
                }
            }
        }

        sort($code);
        sort($prop);

        return [
            'code' => array_values(array_unique($code)),
            'property' => array_values(array_unique($prop)),
        ];
    }

    private function isAllowed(string $fqcn): bool
    {
        foreach ($this->allowedNamespaces as $ns) {
            if (str_starts_with($fqcn, rtrim($ns,'\\').'\\')) {
                return true;
            }
        }
        return false;
    }
}
