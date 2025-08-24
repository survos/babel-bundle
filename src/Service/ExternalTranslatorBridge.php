<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

final class ExternalTranslatorBridge
{
    /** @var object|null */
    private ?object $manager;

    public function __construct(?object $manager = null)
    {
        // Soft dep: Survos\TranslatorBundle\Service\TranslatorManager (if present)
        $this->manager = $manager;
    }

    public function isAvailable(): bool
    {
        return \is_object($this->manager)
            && \method_exists($this->manager, 'names')
            && \method_exists($this->manager, 'by')
            && \method_exists($this->manager, 'default');
    }

    /** @return string[] */
    public function names(): array
    {
        if (!$this->isAvailable()) return [];
        /** @var array $names */
        $names = $this->manager->names();
        return array_values($names);
    }

    /**
     * @return array{translatedText:string, detectedSource:string, engine:string}
     */
    public function translate(string $text, string $from, string $to, ?string $engine = null, bool $html = false): array
    {
        if (!$this->isAvailable()) {
            throw new \LogicException('Translator bundle not installed.');
        }

        $svc = $engine
            ? $this->manager->by($engine)
            : $this->manager->default();

        // No hard typehints; instantiate DTOs by FQCN to keep this a soft dep
        $req = new \Survos\TranslatorBundle\Model\TranslationRequest($text, $from, $to, $html);
        /** @var \Survos\TranslatorBundle\Model\TranslationResult $res */
        $res = $svc->translate($req);

        return [
            'translatedText' => $res->translatedText,
            'detectedSource' => $res->detectedSource,
            'engine'         => $svc->getName(),
        ];
    }
}
