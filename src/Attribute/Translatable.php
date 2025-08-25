<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Translatable
{
    public function __construct(
        /** Optional context/key name if different from the property name */
        public ?string $name = null,
        public ?string $context = null,
        // idea: options for priority or quality check?
    ) {}
}
