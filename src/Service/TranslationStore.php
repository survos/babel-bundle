<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;

final class TranslationStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        public array $translatableIndex = [], // can be injected by compiler pass
        public readonly array $enabledLocales = [], // inject from kernel.enabled_locales
    ) {}

    public function setTranslatableIndex(array $index): void { $this->translatableIndex = $index; }

    private function resolveClasses(): array
    {
        $strClass = '\\App\\Entity\\Str';
        $trClass  = '\\App\\Entity\\StrTranslation';
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $strClass = $strClass ?: '\\Survos\\PixieBundle\\Entity\\Str';
            $trClass  = $trClass  ?: '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }
        return [$strClass, $trClass];
    }

    /**
     * Stream Str rows missing a target-locale translation.
     *
     * @return iterable<object> Str entities
     */
    public function iterateMissing(string $targetLocale, ?string $source = null, int $limit = 0): iterable
    {
        [$strClass, $trClass] = $this->resolveClasses();

        $qb = $this->em->getRepository($strClass)->createQueryBuilder('s');
        $qb->leftJoin($trClass, 't', Join::WITH, 't.hash = s.hash AND t.locale = :target')
            ->andWhere('t.hash IS NULL')
            ->setParameter('target', $targetLocale);

        if (property_exists($strClass, 'srcLocale') && $source) {
            $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    /** Count how many Str rows are missing a translation for $targetLocale. */
    public function countMissing(string $targetLocale, ?string $source = null): int
    {
        [$strClass, $trClass] = $this->resolveClasses();

        $qb = $this->em->getRepository($strClass)->createQueryBuilder('s')
            ->select('COUNT(s.hash)')
            ->leftJoin($trClass, 't', Join::WITH, 't.hash = s.hash AND t.locale = :target')
            ->andWhere('t.hash IS NULL')
            ->setParameter('target', $targetLocale);

        if (property_exists($strClass, 'srcLocale') && $source) {
            $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Summary by locale:
     *  - translated = text <> '' (or status != 'untranslated' if status exists)
     *  - untranslated = text = '' (or status == 'untranslated' if exists)
     * Returns: [locale => ['translated' => n, 'untranslated' => m, 'total' => t]]
     */
    public function getStatusSummary(array $locales): array
    {
        [, $trClass] = $this->resolveClasses();
        $repo = $this->em->getRepository($trClass);

        $hasStatus = property_exists($trClass, 'status');

        $summary = [];
        foreach ($locales as $loc) {
            $qb = $repo->createQueryBuilder('t')
                ->select('COUNT(t.hash) AS total');

            if ($hasStatus) {
                $qb2 = $repo->createQueryBuilder('t2')
                    ->select('COUNT(t2.hash)')
                    ->andWhere('t2.locale = :locale')
                    ->andWhere('t2.status = :u');
                $untranslated = (int) $qb2->getQuery()
                    ->setParameter('locale', $loc)
                    ->setParameter('u', 'untranslated')
                    ->getSingleScalarResult();

                $total = (int) $qb->andWhere('t.locale = :locale')
                    ->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();
                $translated = max(0, $total - $untranslated);
            } else {
                // Fallback: text == '' means untranslated
                $qbTotal = $repo->createQueryBuilder('t')
                    ->select('COUNT(t.hash)')
                    ->andWhere('t.locale = :locale');
                $total = (int) $qbTotal->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();

                $qbUn = $repo->createQueryBuilder('t')
                    ->select('COUNT(t.hash)')
                    ->andWhere('t.locale = :locale')
                    ->andWhere("COALESCE(t.text, '') = ''");
                $untranslated = (int) $qbUn->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();

                $translated = max(0, $total - $untranslated);
            }

            $summary[$loc] = [
                'translated'   => $translated,
                'untranslated' => $untranslated,
                'total'        => $total,
            ];
        }

        return $summary;
    }

    // ----- existing hash/get/upsert/flush helpers -----
    public function hash(string $text, string $srcLocale, ?string $context = null): string
    {
        return hash('xxh3', $srcLocale."\0".$context."\0".$text);
    }

    public function get(string $hash, string $locale): ?string
    {
        // some applications, like a pixie manager, have both!  We need to select by $em, in the constructor?
        $class = '\\App\\Entity\\StrTranslation';
        if (!class_exists($class)) {
            $class = '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }
        $repo = $this->em->getRepository($class);
        $tr = $repo->findOneBy(['hash' => $hash, 'locale' => $locale]);
        return $tr?->text;
    }

    public function upsert(
        string $hash,
        string $original,
        string $srcLocale,
        ?string $context,
        string $locale,
        string $text
    ): object {
        $strClass = '\\App\\Entity\\Str';
        $trClass  = '\\App\\Entity\\StrTranslation';
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $strClass = '\\Survos\\PixieBundle\\Entity\\Str';
            $trClass  = '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }

        // Ensure Str exists
        $strRepo = $this->em->getRepository($strClass);
        if (!$str = $strRepo->find($hash)) {
            $str = new $strClass($hash, $original, $srcLocale, $context);
            $this->em->persist($str);
        }
        $str->updatedAt = new \DateTimeImmutable();

        // Always ensure source locale StrTranslation
        $trRepo = $this->em->getRepository($trClass);
        if (!$tr = $trRepo->findOneBy(['hash' => $hash, 'locale' => $srcLocale])) {
            $tr = new $trClass($hash, $srcLocale, $original);
            $this->em->persist($tr);
        }
        $tr->text      = $original;
        $tr->updatedAt = new \DateTimeImmutable();

        // 👉 Ensure placeholder rows for all enabled locales
        foreach ($this->enabledLocales as $loc) {
            if ($loc === $srcLocale) {
                continue; // skip source, already handled
            }
            if (!$trRepo->findOneBy(['hash' => $hash, 'locale' => $loc])) {
                $un = new $trClass($hash, $loc, '');
                if (property_exists($un, 'status')) {
                    $un->status = 'untranslated';
                }
                $un->updatedAt = new \DateTimeImmutable();
                $this->em->persist($un);
            }
        }

        return $tr;
    }

    /** Helper for fast lookup from listeners/services. */
    public function getEntityConfig(object|string $entity): ?array
    {
        $class = \is_object($entity) ? $entity::class : $entity;
        return $this->translatableIndex[$class] ?? null;
    }

    public function flush(): void { $this->em->flush(); }
    public function clearTranslationsOnly(): void
    {
        [, $trClass] = $this->resolveClasses();
        $this->em->clear($trClass);
    }
}
