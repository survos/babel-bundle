<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Repository\StrRepository;
use Survos\BabelBundle\Repository\StrTranslationRepository;
use Survos\LibreTranslateBundle\Service\TranslationClientService;

final class CodeStorage implements StringStorage
{
    public function __construct(
        private ManagerRegistry $registry,
        private TranslationClientService $translator,
    ) {}

    public function populate(object $carrier, ?string $emName = null): int
    {
        $attr = (new \ReflectionClass($carrier))->getAttributes(BabelStorage::class)[0] ?? null;
        if ($attr && $attr->newInstance()->mode !== StorageMode::Code) return 0;
        if (!\method_exists($carrier, 'getStringCodeMap') || !\method_exists($carrier, 'getOriginalFor')) return 0;

        $map = $carrier->getStringCodeMap();
        $src = $carrier->getSourceLocale() ?? 'en';

        $items = [];
        foreach ($map as $field => $code) {
            $original = $carrier->getOriginalFor($field);
            if (!\is_string($original) || $original === '') continue;
            $items[] = [$code, $original, $src];
        }

        $em = $this->registry->getManager($emName);
        /** @var StrRepository $repo */
        $repo = $em->getRepository(Str::class);
        return $repo->upsertMany($items);
    }

    public function translate(object $carrier, string $locale, bool $onlyMissing = true, ?string $emName = null): int
    {
        $em    = $this->registry->getManager($emName);
        /** @var StrRepository $sRepo */
        $sRepo = $em->getRepository(Str::class);
        /** @var StrTranslationRepository $tRepo */
        $tRepo = $em->getRepository(\Survos\BabelBundle\Entity\StrTranslation::class);

        if (!\method_exists($carrier, 'getStringCodeMap')) return 0;

        $n = 0;
        foreach ($carrier->getStringCodeMap() as $field => $code) {
            /** @var \Survos\BabelBundle\Entity\Str|null $str */
            $str = $sRepo->find($code);
            if (!$str) continue;
            $t = $str->t;
            if ($onlyMissing && isset($t[$locale]) && $t[$locale] !== '') continue;

            $text = $this->translator->translate($str->original, $str->srcLocale, $locale);
            $tRepo->upsertTranslation($code, $locale, $text, false);
            $n++;
        }
        return $n;
    }
}
