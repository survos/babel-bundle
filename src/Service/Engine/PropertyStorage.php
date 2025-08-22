<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

use Survos\LibreTranslateBundle\Service\TranslationClientService;

final class PropertyStorage implements StringStorage
{
    public function __construct(private TranslationClientService $translator) {}

    public function populate(object $carrier, ?string $emName = null): int
    {
        // no-op for property mode — originals live on the entity
        return 0;
    }

    public function translate(object $carrier, string $locale, bool $onlyMissing = true, ?string $emName = null): int
    {
        foreach (['getTranslatableFields','getText','setText','getSourceLocale'] as $m) {
            if (!\method_exists($carrier, $m)) return 0;
        }

        $fields = $carrier->getTranslatableFields();
        $src    = $carrier->getSourceLocale() ?? 'en';
        $n=0;

        foreach ($fields as $f) {
            $cur = $carrier->getText($f, $locale);
            if ($onlyMissing && \is_string($cur) && $cur !== '') continue;
            $source = $carrier->getText($f, $src);
            if (!\is_string($source) || $source === '') continue;
            $carrier->setText($f, $locale, $this->translator->translate($source, $src, $locale));
            $n++;
        }
        return $n;
    }
}
