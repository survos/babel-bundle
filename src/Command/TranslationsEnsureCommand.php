<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\EventListener\StringBackedTranslatableFlushSubscriber;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
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
#[AsCommand('babel:translations:ensure', 'Ensure (str_hash, locale) rows exist in str_translation for target locale(s)')]
final class TranslationsEnsureCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $localeContext,
        private TranslatableIndex $translatableIndex,
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
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found. Adjust --str-class/--tr-class.');
            return Command::FAILURE;
        }

        $targets = $this->resolveTargetLocales($io, $localesArg, $all);

        if ($targets === []) {
            return Command::INVALID;
        }

        if ($source !== null && trim($source) !== '') {
            $source = HashUtil::normalizeLocale($source);
        } else {
            $source = null;
        }

        $grandCreated = 0;
        $grandExists  = 0;
        $grandSeen    = 0;

        foreach ($targets as $locale) {
            $locale = HashUtil::normalizeLocale((string) $locale);
            if ($locale === '') {
                continue;
            }

            $io->section("Locale: {$locale}");

            $strRepo = $this->em->getRepository($strClass);
            $trRepo  = $this->em->getRepository($trClass);

            // Base STR query
            $baseQb = $strRepo->createQueryBuilder('s');
            if ($source !== null) {
                $baseQb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
            }

            // Total
            $countQb = clone $baseQb;
            $total = (int) $countQb->select('COUNT(s.hash)')->getQuery()->getSingleScalarResult();
            if ($limit > 0) {
                $total = min($total, $limit);
            }

            if ($total === 0) {
                $io->note('No source rows match filters for this locale.');
                continue;
            }

            // Iterator
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
                    $progressBar->advance();
                    $progressBar->setMessage((string) $seen, 'message1');
                    $progressBar->setMessage((string) $created, 'message2');
                    $progressBar->setMessage((string) $exists, 'message3');
                    if ($limit > 0 && $seen >= $limit) { break; }
                    continue;
                }

                $srcLocale = HashUtil::normalizeLocale((string) ($str->srcLocale ?? ''));

                // Skip same-locale rows (and optionally fail-fast if you prefer).
                if ($srcLocale !== '' && $locale === $srcLocale) {
                    $progressBar->advance();
                    $progressBar->setMessage((string) $seen, 'message1');
                    $progressBar->setMessage((string) $created, 'message2');
                    $progressBar->setMessage((string) $exists, 'message3');
                    if ($limit > 0 && $seen >= $limit) { break; }
                    continue;
                }

                // If you want fail-fast instead of silent skip, uncomment:
                // $this->assertNotSameLocale($srcLocale, $locale, $strHash);

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
                } else {
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

    /** @return list<string> */
    private function resolveTargetLocales(SymfonyStyle $io, ?string $localesArg, bool $all): array
    {
        if ($localesArg !== null && trim($localesArg) !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
            $parts = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $parts)));
            return array_values(array_filter($parts, static fn(string $l) => $l !== ''));
        }

        if ($all) {
            $enabled = $this->localeContext->getEnabled();
            if ($enabled === []) {
                $io->warning('No enabled locales found. Configure kernel.enabled_locales or pass locales.');
                return [];
            }
            return array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $enabled)));
        }

        // Default behavior: use enabled locales but make it explicit.
        $enabled = $this->localeContext->getEnabled();
        if ($enabled === []) {
            $io->warning('Specify locales (e.g. "es,fr") or pass --all; no enabled locales configured.');
            return [];
        }
        $io->note('No locales provided; defaulting to enabled_locales.');
        return array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $enabled)));
    }

    private function assertNotSameLocale(string $srcLocale, string $targetLocale, string $strHash): void
    {
        $src = HashUtil::normalizeLocale($srcLocale);
        $tgt = HashUtil::normalizeLocale($targetLocale);

        if ($src !== '' && $tgt !== '' && $src === $tgt) {
            throw new \LogicException(sprintf(
                'Refusing to create StrTranslation for same source+target locale (%s). str_hash=%s',
                $src,
                $strHash
            ));
        }
    }
}
