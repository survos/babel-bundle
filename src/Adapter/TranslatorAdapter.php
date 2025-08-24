<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Adapter;

use Survos\BabelBundle\Contract\TranslatorInterface;

final class TranslatorAdapter implements TranslatorInterface
{
    /** Soft-dependency on Survos\TranslatorBundle\Service\TranslatorManager (nullable) */
    public function __construct(private readonly ?object $manager = null) {}

    public function translate(string $text, string $from, string $to, ?string $engine = null, bool $html = false): string
    {
        if (!$this->manager) {
            throw new \LogicException('SurvosTranslatorBundle is not installed; cannot use external translator engines.');
        }

        // Duck-typed to avoid a hard dependency on the Translator bundle types
        $svc = $engine ? $this->manager->by($engine) : $this->manager->default();

        $reqClass = 'Survos\\TranslatorBundle\\Model\\TranslationRequest';
        /** @var object $request */
        $request = new $reqClass($text, $from, $to, $html);

        /** @var object $result */
        $result = $svc->translate($request);

        /** @var string $translated */
        $translated = $result->translatedText ?? (string)($result->translated_text ?? '');

        return $translated;
    }
}
