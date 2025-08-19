<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('babel:populate', 'Populates missing StrTranslation entities from Str (optionally filtered by source locale) with status=untranslated')]
final class BabelPopulateCommand
{
    public function __construct(
        private readonly TranslationStore $store,
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Target locales, comma-delimited (omit to use --all)')] ?string $localesArg = null,
        #[Option('Filter by source locale (Str.srcLocale)')] ?string $source = null,
        #[Option('Process all enabled locales when argument is empty')] bool $all = false,
        #[Option('Flush every N rows')] int $batchSize = 500,
        #[Option('Limit number of Str rows to scan per locale (0 = all)')] int $limit = 0,
        #[Option('Preview only, do not write')] bool $dryRun = false,
    ): int {
        // Resolve locales
        $locales = [];
        if (is_string($localesArg) && trim($localesArg) !== '') {
            $locales = array_values(array_filter(array_map(fn($s) => trim($s), explode(',', $localesArg))));
        } elseif ($all) {
            $locales = $this->enabledLocales ?: ['en'];
        } else {
            $io->error('Specify target locales (comma-delimited) or pass --all to use framework.enabled_locales.');
            return 1;
        }

        $io->title('Populate missing translations');
        $io->writeln('Locales: <info>'.implode(', ', $locales).'</info>');
        if ($source) {
            $io->writeln('Source filter: <info>'.$source.'</info>');
        }
        if ($dryRun) {
            $io->warning('Dry-run: no database writes will be committed.');
        }

        $grandCreated = 0;
        $grandSeen    = 0;

        foreach ($locales as $locale) {
            // Prepare progress bar
            $total = $this->store->countMissing($locale, $source);
            $countForProgress = $limit > 0 ? min($total, $limit) : $total;

            $io->section(sprintf('Locale %s — missing: %d%s',
                $locale,
                $total,
                $limit > 0 ? " (limited to $countForProgress)" : ''
            ));

            $progress = $io->createProgressBar($countForProgress);
            $progress->start();

            $seen = 0;
            $created = 0;

            foreach ($this->store->iterateMissing($locale, $source, $limit) as $str) {
                ++$seen;

                // Extract essentials from Str
                $hash      = method_exists($str, 'getHash') ? $str->getHash()      : (property_exists($str, 'hash') ? $str->hash : null);
                $original  = method_exists($str, 'getOriginal') ? $str->getOriginal() : (property_exists($str, 'original') ? $str->original : '');
                $srcLocale = method_exists($str, 'getSrcLocale') ? $str->getSrcLocale() : (property_exists($str, 'srcLocale') ? $str->srcLocale : '');
                $context   = method_exists($str, 'getContext') ? $str->getContext() : (property_exists($str, 'context') ? $str->context : null);

                if (!\is_string($hash) || $hash === '') {
                    // Skip malformed row
                    $progress->advance();
                    continue;
                }

                if (!$dryRun) {
                    $tr = $this->store->upsert(
                        hash:      $hash,
                        original:  (string)$original,
                        srcLocale: (string)$srcLocale,
                        context:   $context ? (string)$context : null,
                        locale:    $locale,
                        text:      '' // blank; to be batch-translated later
                    );
                    if (property_exists($tr, 'status') && ($tr->text === '' || $tr->text === null)) {
                        $tr->status = 'untranslated';
                    }
                }

                ++$created;
                ++$grandCreated;
                ++$grandSeen;

                if (!$dryRun && ($seen % $batchSize) === 0) {
                    $this->store->flush();
                    $this->store->clearTranslationsOnly();
                }

                // cap progress at planned bar length
                if ($seen <= $countForProgress) {
                    $progress->advance();
                }
            }

            if (!$dryRun) {
                $this->store->flush();
            }

            if ($seen < $countForProgress) {
                $progress->advance($countForProgress - $seen);
            }
            $progress->finish();
            $io->newLine(2);
            $io->writeln(sprintf('Locale %s: processed %d, created %d', $locale, $seen, $created));
        }

        // Final summary by language
        $io->section('Summary by language');
        $summary = $this->store->getStatusSummary($locales);

        $rows = [];
        foreach ($locales as $loc) {
            $s = $summary[$loc] ?? ['translated' => 0, 'untranslated' => 0, 'total' => 0];
            $rows[] = [
                'locale'        => $loc,
                'translated'    => $s['translated'],
                'untranslated'  => $s['untranslated'],
                'total'         => $s['total'],
            ];
        }
        $io->table(['Locale', 'Translated', 'Untranslated', 'Total'], array_map(fn($r) => [
            $r['locale'], (string)$r['translated'], (string)$r['untranslated'], (string)$r['total'],
        ], $rows));

        $io->success(sprintf('Done. Processed %d, created %d missing StrTranslation rows.', $grandSeen, $grandCreated));
        if ($dryRun) {
            $io->note('Dry-run was enabled: no changes were written.');
        }

        return 0;
    }
}
