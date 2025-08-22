<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

enum StorageMode: string {
    case Code = 'code'; // embedded in $tCode, used by Pixie
    case Property = 'property'; // a single string property, e.g. $label_backed.
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class BabelStorage
{
        public function __construct(public StorageMode $mode=StorageMode::Property) {}
}
