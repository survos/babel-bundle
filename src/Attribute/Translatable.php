<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Translatable
{
    public function __construct(
        public ?string $context = null   // optional domain or hint for hashing
    ) {}
}
