<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Util\HashUtil;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ensure there is a StrTranslation row for each (hash, targetLocale).
 * Useful as a backfill if some strings predate the onFlush/postFlush upserter.
 */
#[AsCommand('babel:translations:ensure', 'Ensure (hash, locale) rows exist in str_translation for target locale(s)')]
final class TranslationsEnsureCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private LocaleContext $localeContext,
    ) {}

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
        // Resolve targets (your LocaleContext already knows)
        $targets = $this->localeContext->getEnabled();
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found. Adjust --str-class/--tr-class.');
            return Command::FAILURE;
        }

        $grandCreated = 0;
        $grandExists  = 0;
        $grandSeen    = 0;

        foreach ($targets as $locale) {
            $io->section("Locale: {$locale}");

            $strRepo = $this->em->getRepository($strClass);
            $trRepo  = $this->em->getRepository($trClass);

            // Build the same base query we will iterate
            $baseQb = $strRepo->createQueryBuilder('s');
            if ($source !== null) {
                $baseQb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
            }

            // Compute accurate total (respecting --source)
            $countQb = clone $baseQb;
            $total = (int) $countQb->select('COUNT(s.hash)')->getQuery()->getSingleScalarResult();
            if ($limit > 0) {
                $total = min($total, $limit);
            }

            if ($total === 0) {
                $io->note('No source rows match filters for this locale.');
                continue;
            }

            // Prepare the iterator for actual processing
            if ($limit > 0) {
                $baseQb->setMaxResults($limit);
            }
            $iter = $baseQb->getQuery()->toIterable();

            // Counters
            $created = 0;
            $exists  = 0;
            $seen    = 0;

            $progressBar = new ProgressBar($io, $total);

            $progressBar->setRedrawFrequency(200);
            $progressBar->setFormat(
                ' %current%/%max% [%bar%] %percent:3s%%  • seen:%message1%  created:%message2%  exists:%message3%'
            );
            $progressBar->setMessage('0', 'message1');
            $progressBar->setMessage('0', 'message2');
            $progressBar->setMessage('0', 'message3');

            foreach ($iter as $str) {
                /** @var object $str */
                ++$seen;
                /** @var string $strHash */
                $strHash = $str->hash ?? '';
                if ($strHash === '') {
                    $progressBar->advance();
                    $progressBar->setMessage((string) $seen, 'message1');
                    $progressBar->setMessage((string) $created, 'message2');
                    $progressBar->setMessage((string) $exists, 'message3');
                    if ($limit > 0 && $seen >= $limit) { break; }
                    continue;
                }

                // Exists?  (check by UNIQUE(str_hash, locale))
                $already = (bool) $trRepo->createQueryBuilder('t')
                    ->select('t.hash')
                    ->andWhere('t.strHash = :sh AND t.locale = :l')
                    ->setParameter('sh', $strHash)
                    ->setParameter('l', $locale)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($already) {
                    ++$exists;
                } else {
                    if (!$dryRun) {
                        $tr = new $trClass(
                            HashUtil::calcTranslationKey($strHash, $locale),
                            $strHash,
                            $locale,
                            null
                        );
                        $this->em->persist($tr);
                    }
                    ++$created;

                    if (!$dryRun && ($created % $batchSize) === 0) {
                        $this->em->flush();
                        $this->em->clear();
                        // re-acquire after clear
                        $strRepo = $this->em->getRepository($strClass);
                        $trRepo  = $this->em->getRepository($trClass);
                    }
                }

                $progressBar->advance();
                $progressBar->setMessage((string) $seen, 'message1');
                $progressBar->setMessage((string) $created, 'message2');
                $progressBar->setMessage((string) $exists, 'message3');

                if ($limit > 0 && $seen >= $limit) { break; }
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

            $io->success(sprintf('Locale %s → seen %d, created %d, existed %d', $locale, $seen, $created, $exists));

            $grandCreated += $created;
            $grandExists  += $exists;
            $grandSeen    += $seen;
        }

        $io->writeln('');
        $io->success(sprintf(
            'All locales done → seen %d, created %d, existed %d%s',
            $grandSeen,
            $grandCreated,
            $grandExists,
            $dryRun ? ' (dry-run)' : ''
        ));
        return Command::SUCCESS;
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
