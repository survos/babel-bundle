<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Translation;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Reads translated strings straight out of babel storage: domain == Str.context,
 * key == Str.code, text == the matching StrTranslation.text for this locale.
 *
 * Populated via lingua:harvest:routes (or any other Str writer) + lingua:push/pull.
 */
final class BabelTranslationLoader implements LoaderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        $strClass = class_exists('App\\Entity\\Str') ? 'App\\Entity\\Str' : Str::class;
        $trClass = class_exists('App\\Entity\\StrTranslation') ? 'App\\Entity\\StrTranslation' : StrTranslation::class;

        try {
            $rows = $this->em->createQueryBuilder()
                ->select('s.code AS code, s.meta AS meta, t.text AS text')
                ->from($strClass, 's')
                ->join($trClass, 't', 'WITH', 't.strCode = s.code')
                ->andWhere('s.context = :domain')
                ->andWhere('t.targetLocale = :locale')
                ->andWhere('t.text IS NOT NULL')
                ->setParameter('domain', $domain)
                ->setParameter('locale', $locale)
                ->getQuery()
                ->getArrayResult();
        } catch (\Doctrine\DBAL\Exception $e) {
            // The translator's catalogue warmup runs this eagerly (e.g. during cache:clear), which
            // would otherwise hard-fail a build/deploy on a schema that hasn't been migrated yet --
            // an empty catalogue degrades to untranslated source strings instead ("publish what we
            // can"), same fallback philosophy as the rest of this translation pipeline.
            return new MessageCatalogue($locale, [$domain => []]);
        }

        $translations = [];
        foreach ($rows as $row) {
            // Long |trans keys get hashed into Str.code (VARCHAR(64)); the real key lives in meta.
            $key = (string) ($row['meta']['key'] ?? $row['code']);
            $translations[$key] = (string) $row['text'];
        }

        return new MessageCatalogue($locale, [$domain => $translations]);
    }
}
