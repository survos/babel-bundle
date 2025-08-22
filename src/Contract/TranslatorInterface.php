<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Contract;

interface TranslatorInterface
{
    public function translate(string $text, string $from, string $to): string;
}
