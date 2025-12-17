<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Survos\BabelBundle\Contracts\CodeStringCarrier;
use Survos\BabelBundle\Service\Engine\CodeStorage;
use Survos\BabelBundle\Service\Engine\PropertyStorage;
use Survos\BabelBundle\Service\Engine\StringStorage;

final class StringStorageRouter
{
    public function __construct(
        private readonly CodeStorage $code,
        private readonly PropertyStorage $property,
        private readonly TranslatableIndex $index,
    ) {
        assert(false, "This is probably no longer needed, let's come up with a better way");
    }

    /** Choose a storage for the given carrier. */
    public function storageFor(object $carrier): StringStorage
    {
        if ($carrier instanceof CodeStringCarrier) {
            return $this->code;
        }

        // Property-mode if the class is in our compile-time index
        $class = $carrier::class;
        if ($this->index->fieldsFor($class) !== []) {
            return $this->property;
        }

        throw new \RuntimeException(sprintf(
            'No storage engine available for %s (not CodeStringCarrier and no translatable fields found).',
            $class
        ));
    }

    /** Convenience pass-through used by ingestion code. */
    public function populate(object $carrier, ?string $emName = null): int
    {
        return $this->storageFor($carrier)->populate($carrier, $emName);
    }
}
