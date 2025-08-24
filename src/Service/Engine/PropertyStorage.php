<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

/**
 * Property-mode storage is handled by the Doctrine flush subscriber.
 * This class exists only to satisfy the router API; it does nothing.
 */
final class PropertyStorage implements StringStorage
{
    public function __construct() {}

    public function populate(object $carrier, ?string $emName = null): int
    {
        // No-op: property-mode entities are captured by the onFlush/postFlush subscriber.
        return 0;
    }
}
