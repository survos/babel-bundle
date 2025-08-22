<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contracts;

interface CodeStringCarrier
{
    public function getStringCodeMap(): array;
    public function getOriginalFor(string $field): ?string;
    public function getSourceLocale(): ?string;
}
