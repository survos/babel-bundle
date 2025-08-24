<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Contracts\CodeStringCarrier;
use Survos\BabelBundle\Contracts\PropertyStringCarrier;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;

final class StringStorageRouter
{
    public function __construct(
        private readonly CodeStorage $code,
        private readonly PropertyStorage $property,
    ) {}

    public function populate(object $carrier, ?string $emName = null): int
    {
        return match ($this->resolveMode($carrier)) {
            StorageMode::Code     => $this->code->populate($carrier, $emName),
            StorageMode::Property => $this->property->populate($carrier, $emName),
        };
    }

    private function resolveMode(object $carrier): StorageMode
    {
        // 1) Interfaces win (most explicit)
        if ($carrier instanceof CodeStringCarrier)     { return StorageMode::Code; }
        if ($carrier instanceof PropertyStringCarrier) { return StorageMode::Property; }

        // 2) #[BabelStorage(mode: ...)] attribute
        $rc = new \ReflectionClass($carrier);
        $attr = $rc->getAttributes(BabelStorage::class)[0] ?? null;
        if ($attr) {
            $inst = $attr->newInstance();
            $mode = $inst->mode ?? null;
            if ($mode instanceof StorageMode) {
                return $mode;
            }
            if (\is_string($mode)) {
                return StorageMode::from($mode);
            }
        }

        // 3) Default
        return StorageMode::Property;
    }
}
