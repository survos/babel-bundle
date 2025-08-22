<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Fixtures\Service;

use Survos\BabelBundle\Contract\TranslatorInterface;

final class FakeTranslator implements TranslatorInterface
{
    public function translate(string $text, string $from, string $to): string
    {
        return "[{$to}] " . $text;
    }
}
