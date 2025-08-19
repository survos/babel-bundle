<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Minimal translation store:
 * - compute deterministic hash
 * - fetch translated text by (hash, locale)
 * - upsert Str/StrTranslation (concrete classes provided by the app or pixie)
 */
final class TranslationStore
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function hash(string $text, string $srcLocale, ?string $context = null): string
    {
        return hash('xxh3', $srcLocale."\0".$context."\0".$text);
    }

    /** Lookup translated string or null if missing. */
    public function get(string $hash, string $locale): ?string
    {
        // App must provide concrete entities in its namespace.
        $class = '\\App\\Entity\\StrTranslation';
        if (!class_exists($class)) {
            // Fallback: allow a Survos pixie/app variant if desired.
            // You can override this class in container if needed.
            $class = '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }
        $repo = $this->em->getRepository($class);
        $tr = $repo->findOneBy(['hash' => $hash, 'locale' => $locale]);
        return $tr?->text;
    }

    /**
     * Ensure source and translation rows exist/upserted.
     * NOTE: The concrete entities must be defined by the host app (or pixie) extending the mapped superclasses.
     */
    public function upsert(string $hash, string $original, string $srcLocale, ?string $context, string $locale, string $text): void
    {
        $strClass = '\\App\\Entity\\Str';
        $trClass  = '\\App\\Entity\\StrTranslation';
        if (!class_exists($strClass) || !class_exists($trClass)) {
            // optionally fallback to Survos\PixieBundle entities
            $strClass = $strClass  ?: '\\Survos\\PixieBundle\\Entity\\Str';
            $trClass  = $trClass   ?: '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }

        $strRepo = $this->em->getRepository($strClass);
        $str = $strRepo->find($hash) ?? new $strClass($hash, $original, $srcLocale, $context);
        $str->updatedAt = new \DateTimeImmutable();
        $this->em->persist($str);

        $trRepo = $this->em->getRepository($trClass);
        $tr = $trRepo->findOneBy(['hash' => $hash, 'locale' => $locale]) ?? new $trClass($hash, $locale, $text);
        $tr->text      = $text;
        $tr->updatedAt = new \DateTimeImmutable();
        $this->em->persist($tr);
    }
}
