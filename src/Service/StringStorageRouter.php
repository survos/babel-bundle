<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Survos\BabelBundle\Service\Engine\StringStorage;

final class StringStorageRouter
{
    public function __construct(
        private StringStorage $codeEngine,
        private StringStorage $propertyEngine,
    ) {}

    public function for(object $carrier): StringStorage
    {
        // Attribute-driven routing if available
        $ref = new \ReflectionClass($carrier);
        $attrs = $ref->getAttributes(\Survos\BabelBundle\Attribute\BabelStorage::class);
        if ($attrs) {
            $mode = $attrs[0]->newInstance()->mode;
            return $mode === \Survos\BabelBundle\Attribute\StorageMode::Property
                ? $this->propertyEngine
                : $this->codeEngine;
        }

        // Fallback heuristic
        return \method_exists($carrier, 'getStringCodeMap')
            ? $this->codeEngine
            : $this->propertyEngine;
    }
}
