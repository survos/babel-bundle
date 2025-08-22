<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

interface StringStorage
{
    public function populate(object $carrier, ?string $emName = null): int;
    public function translate(object $carrier, string $locale, bool $onlyMissing = true, ?string $emName = null): int;
}
