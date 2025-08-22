<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

enum StorageMode: string { case Code = 'code'; case Property = 'property'; }

#[\Attribute(\Attribute::TARGET_CLASS)]
final class BabelStorage
{
    public function __construct(public StorageMode $mode) {}
}
