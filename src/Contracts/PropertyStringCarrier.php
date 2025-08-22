<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contracts;

interface PropertyStringCarrier
{
    /** @return list<string> */
    public function getTranslatableFields(): array;
    public function getText(string $field, string $locale): ?string;
    public function setText(string $field, string $locale, string $text): void;
    public function getSourceLocale(): ?string;
}
