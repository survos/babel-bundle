<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ensure there is a StrTranslation row for each (hash, targetLocale).
 * Useful as a backfill if some strings predate the onFlush/postFlush upserter.
 */
#[AsCommand('babel:translations:ensure', 'Ensure (hash, locale) rows exist in str_translation for target locale(s)')]
final class TranslationsEnsureCommand # extends Command # AbstractBabelCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private LocaleContext $localeContext,
    ) {
//        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Filter by source (Str.srcLocale) when present')] ?string $source = null,
        #[Option('Batch size for flush/clear')] int $batchSize = 500,
        #[Option('Limit rows per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
        #[Option('Str entity FQCN')] string $strClass = 'App\\Entity\\Str',
        #[Option('StrTranslation entity FQCN')] string $trClass = 'App\\Entity\\StrTranslation',
    ): int {
//        $this->applyLocaleOverride($input);

//        $targets = $this->resolveTargetLocales($io, null, $all);
        $targets = $this->localeContext->getEnabled();

        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found. Adjust --str-class/--tr-class.');
            return 1;
        }

        $grandCreated = 0;

        foreach ($targets as $locale) {
            $io->section("Locale: {$locale}");

            $strRepo = $this->em->getRepository($strClass);
            $trRepo  = $this->em->getRepository($trClass);

            $qb = $strRepo->createQueryBuilder('s');
            if ($source !== null) {
                $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
            }
            if ($limit > 0) $qb->setMaxResults($limit);

            $iter = $qb->getQuery()->toIterable();
            $created = 0; $seen = 0;

            foreach ($iter as $str) {
                /** @var object $str */
                $seen++;
                if (!\is_string($str->hash) || $str->hash === '') continue;

                // Exists?
                $exists = (bool) $trRepo->createQueryBuilder('t')
                    ->select('t.hash')
                    ->andWhere('t.hash = :h AND t.locale = :l')
                    ->setParameter('h', $str->hash)
                    ->setParameter('l', $locale)
                    ->setMaxResults(1)
                    ->getQuery()->getOneOrNullResult();
                if ($exists) continue;

                if (!$dryRun) {
                    $tr = new $trClass($str->hash, $locale, null);
                    // this happens in the constructor
//                    $tr->updatedAt = new \DateTimeImmutable();
                    $this->em->persist($tr);
                }

                $created++;

                if (!$dryRun && ($created % $batchSize) === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    // re-acquire after clear
                    $strRepo = $this->em->getRepository($strClass);
                    $trRepo  = $this->em->getRepository($trClass);
                }

                if ($limit > 0 && $seen >= $limit) break;
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

            $grandCreated += $created;
            $io->success(sprintf('Locale %s: created %d StrTranslation row(s).', $locale, $created));
        }

        $io->success(sprintf('Total created: %d', $grandCreated));
        if ($dryRun) $io->note('Dry-run: no changes were written.');
        return 0;
    }

    /** @return list<string>|null */
    private function resolveTargetLocales(SymfonyStyle $io, ?string $localesArg, bool $all): ?array
    {
        if ($localesArg && \trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
            return $targets !== [] ? $targets : null;
        }
        if ($all) {
            $locales = $this->enabledLocales ?: [$this->defaultLocale];
            if ($locales === []) {
                $io->warning('No enabled locales found. Configure framework.enabled_locales or pass locales.');
                return null;
            }
            return array_values(array_unique($locales));
        }
        $io->warning('Specify locales (e.g. "es,fr") or pass --all to use framework.enabled_locales.');
        return null;
    }
}
