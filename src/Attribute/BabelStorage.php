<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class BabelStorage
{
        public function __construct(
            public StorageMode $mode=StorageMode::Property,
        ) {}
}
