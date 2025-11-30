<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\App\Service;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\EngineCapabilities;
use Survos\TranslatorBundle\Model\LanguageDetectionResult;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationBatchResult;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Model\TranslationResult;

final class FakeTranslator implements TranslatorEngineInterface
{
    public function translate(TranslationRequest $req): TranslationResult
    {
        dd($req);
    }


    public function getName(): string
    {
        return 'FakeTranslator';
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        return new TranslationBatchResult([], 'en');
    }

    public function detect(string $text): LanguageDetectionResult
    {
        return new LanguageDetectionResult('en', 0.5);
    }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities();
    }
}
