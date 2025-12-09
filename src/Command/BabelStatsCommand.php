<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    'babel:stats',
    'Show translation statistics for Babel STR/STR_TRANSLATION tables (per-locale coverage).'
)]
final class BabelStatsCommand
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('locale', 'Filter stats to a specific locale (e.g. "es").')]
        ?string $locale = null,
        #[Option('no-bars', 'Disable ASCII coverage bars in the output.')]
        bool $noBars = false,
    ): int {
        $io->title('Babel translation stats');

        $strTable = BabelRuntime::STRING_TABLE;        // typically "str"
        $trTable  = BabelRuntime::TRANSLATION_TABLE;   // typically "str_translation"

        try {
            $totalStr = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$strTable}");
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'Failed to query STR table "%s": %s',
                $strTable,
                $e->getMessage()
            ));

            return Command::FAILURE;
        }

        try {
            $params = [];
            $sqlTrTotal = "SELECT COUNT(*) FROM {$trTable}";
            if ($locale !== null) {
                $sqlTrTotal .= " WHERE locale = :locale";
                $params['locale'] = $locale;
            }

            $totalTr = (int) $this->connection->fetchOne($sqlTrTotal, $params);
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'Failed to query STR_TRANSLATION table "%s": %s',
                $trTable,
                $e->getMessage()
            ));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('STR rows (%s): <info>%d</info>', $strTable, $totalStr));
        $io->writeln(sprintf(
            'STR_TRANSLATION rows (%s%s): <info>%d</info>',
            $trTable,
            $locale ? sprintf(', locale="%s"', $locale) : '',
            $totalTr
        ));
        $io->newLine();

        // Per-locale aggregation: total rows + translated rows (text IS NOT NULL/empty).
        $params = [];
        $sql    = "
            SELECT
                locale,
                COUNT(*) AS total,
                SUM(CASE WHEN text IS NOT NULL AND text <> '' THEN 1 ELSE 0 END) AS translated
            FROM {$trTable}
        ";

        if ($locale !== null) {
            $sql .= " WHERE locale = :locale";
            $params['locale'] = $locale;
        }

        $sql .= " GROUP BY locale ORDER BY locale";

        try {
            /** @var array<int, array{locale:string,total:string,translated:string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'Failed to aggregate per-locale stats: %s',
                $e->getMessage()
            ));

            return Command::FAILURE;
        }

        if ($rows === []) {
            if ($locale) {
                $io->warning(sprintf(
                    'No rows found in %s for locale "%s".',
                    $trTable,
                    $locale
                ));
            } else {
                $io->warning(sprintf(
                    'No rows found in %s. Have you flushed any translatable entities yet?',
                    $trTable
                ));
            }

            return Command::SUCCESS;
        }

        $io->section('Per-locale coverage');

        $table = [];
        $table[] = ['Locale', 'Rows', 'With text', 'Missing', 'Coverage vs STR', $noBars ? '' : 'Bar'];

        foreach ($rows as $r) {
            $loc        = (string) $r['locale'];
            $total      = (int) $r['total'];
            $translated = (int) $r['translated'];
            $missing    = \max(0, $total - $translated);

            // Coverage is measured as translated rows / STR rows (if we have stub rows per STR+locale,
            // this is effectively "percentage of STR rows translated for that locale").
            $coverage = ($totalStr > 0)
                ? (100.0 * $translated / $totalStr)
                : 0.0;

            $bar = $noBars ? '' : $this->coverageBar($coverage);

            $table[] = [
                $loc,
                (string) $total,
                (string) $translated,
                (string) $missing,
                \sprintf('%.1f%%', $coverage),
                $bar,
            ];
        }

        $io->table(
            $table[0],
            \array_slice($table, 1),
        );

        $io->writeln('');
        $io->note('Coverage is computed as: translated STR_TRANSLATION rows / STR rows, per locale.');

        return Command::SUCCESS;
    }

    private function coverageBar(float $pct, int $width = 24): string
    {
        if ($pct < 0) {
            $pct = 0;
        } elseif ($pct > 100) {
            $pct = 100;
        }

        $filled = (int) \round($pct / 100 * $width);
        $empty  = $width - $filled;

        return '['
            . \str_repeat('#', $filled)
            . \str_repeat('.', $empty)
            . ']';
    }
}
