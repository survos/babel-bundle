<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

interface StringStorage
{
    /**
     * Ensure source rows exist in `str` (and, if applicable, source entries in `str_translation`)
     * for this carrier. Returns number of items populated.
     */
    public function populate(object $carrier, ?string $emName = null): int;
}
