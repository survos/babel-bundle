<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Adapter;

use Survos\BabelBundle\Contract\TranslatorInterface;

final class TranslatorAdapter implements TranslatorInterface
{
    /** @var object|null Soft-dep: Survos\TranslatorBundle\Service\TranslatorManager */
    public function __construct(private readonly ?object $manager = null) {}

    public function translate(string $text, string $from, string $to, ?string $engine = null, bool $html = false): string
    {
        if (!$this->manager) {
            throw new \LogicException('SurvosTranslatorBundle is not installed; cannot use external translator engines.');
        }

        // Call via duck-typing to avoid hard dependency
        $svc = $engine
            ? $this->manager->by($engine)
            : $this->manager->default();

        $reqClass = 'Survos\\TranslatorBundle\\Model\\TranslationRequest';
        $res = $svc->translate(new $reqClass($text, $from, $to, $html));

        return $res->translatedText;
    }
}
