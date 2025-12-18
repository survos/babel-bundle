<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\EventListener\StringBackedTranslatableFlushSubscriber;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TargetLocaleResolver;
use Survos\Lingua\Core\Identity\HashUtil;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ensure there is a StrTranslation row for each (str_hash, targetLocale).
 * Useful as a backfill if some strings predate the onFlush/postFlush upserter.
 */
#[AsCommand('babel:ensure', 'Ensure (str_hash, locale) rows exist in str_translation for target locale(s)')]
final class TranslationsEnsureCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $localeContext,
        private readonly TargetLocaleResolver $targetLocaleResolver,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Target locales (comma-delimited) or empty to use enabled_locales')] ?string $localesArg = null,
        #[Option('Filter by source (Str.srcLocale) when present')] ?string $source = null,
        #[Option('Batch size for flush/clear')] int $batchSize = 500,
        #[Option('Limit STR rows (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
        #[Option('Str entity FQCN')] string $strClass = 'App\\Entity\\Str',
        #[Option('StrTranslation entity FQCN')] string $trClass = 'App\\Entity\\StrTranslation',
    ): int {
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found. Adjust --str-class/--tr-class.');
            return Command::FAILURE;
        }

        $explicitTargets = $this->parseLocalesArg($localesArg);

        // Candidate locales are either explicit targets (argument) or enabled_locales.
        $candidateLocales = $explicitTargets ?: $this->localeContext->getEnabled();
        if ($candidateLocales === []) {
            $candidateLocales = [$this->localeContext->getDefault()];
        }
        $candidateLocales = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $candidateLocales)));
        $candidateLocales = array_values(array_filter($candidateLocales, static fn(string $l) => $l !== ''));

        if ($candidateLocales === []) {
            $io->warning('No candidate locales resolved. Pass locales (e.g. "es,fr") or configure enabled_locales.');
            return Command::INVALID;
        }

        if ($source !== null && trim($source) !== '') {
            $source = HashUtil::normalizeLocale($source);
        } else {
            $source = null;
        }

        $strRepo = $this->em->getRepository($strClass);
        $trRepo  = $this->em->getRepository($trClass);

        // Base STR query
        $baseQb = $strRepo->createQueryBuilder('s');
        if ($source !== null) {
            $baseQb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
        }

        // Total STR rows
        $countQb = clone $baseQb;
        $total = (int) $countQb->select('COUNT(s.hash)')->getQuery()->getSingleScalarResult();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $io->note('No source rows match filters.');
            return Command::SUCCESS;
        }

        if ($limit > 0) {
            $baseQb->setMaxResults($limit);
        }
        $iter = $baseQb->getQuery()->toIterable();

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
            ++$seen;

            /** @var string $strHash */
            $strHash = (string) ($str->hash ?? '');
            if ($strHash === '') {
                $this->advance($progressBar, $seen, $created, $exists);
                if ($limit > 0 && $seen >= $limit) { break; }
                continue;
            }

            $srcLocale = HashUtil::normalizeLocale((string) ($str->srcLocale ?? ''));
            if ($srcLocale === '') {
                $srcLocale = $this->localeContext->getDefault();
            }

            // Resolve targets per row (critical: excludes srcLocale).
            $targets = $this->targetLocaleResolver->resolve(
                enabledLocales: $candidateLocales,
                explicitTargets: $explicitTargets ?: null,
                sourceLocale: $srcLocale,
            );

            foreach ($targets as $locale) {
                // Exists? UNIQUE(str_hash, locale)
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
                    continue;
                }

                if (!$dryRun) {
                    $tr = new $trClass(
                        HashUtil::calcTranslationKey($strHash, $locale, StringBackedTranslatableFlushSubscriber::BABEL_ENGINE),
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
                    $strRepo = $this->em->getRepository($strClass);
                    $trRepo  = $this->em->getRepository($trClass);
                }
            }

            $this->advance($progressBar, $seen, $created, $exists);

            if ($limit > 0 && $seen >= $limit) { break; }
        }

        if (!$dryRun) {
            $this->em->flush();
            $this->em->clear();
        }

        $io->writeln('');
        $io->success(sprintf(
            'Done → STR seen %d, StrTranslation created %d, existed %d%s',
            $seen,
            $created,
            $exists,
            $dryRun ? ' (dry-run)' : ''
        ));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function parseLocalesArg(?string $localesArg): array
    {
        if ($localesArg === null || trim($localesArg) === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
        $parts = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $parts)));
        return array_values(array_filter($parts, static fn(string $l) => $l !== ''));
    }

    private function advance(ProgressBar $bar, int $seen, int $created, int $exists): void
    {
        $bar->advance();
        $bar->setMessage((string) $seen, 'message1');
        $bar->setMessage((string) $created, 'message2');
        $bar->setMessage((string) $exists, 'message3');
    }
}
