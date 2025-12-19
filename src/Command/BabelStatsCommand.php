<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    'babel:stats',
    'Show translation statistics for Babel STR/STR_TR (per-locale coverage), plus Term/TermSet overview.'
)]
final class BabelStatsCommand
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter stats to a specific target locale (e.g. "es").')]
        ?string $locale = null,
        #[Option('Disable ASCII coverage bars in the output.')]
        bool $noBars = false,
        #[Option('Include Term/TermSet counts.')]
        bool $terms = true,
    ): int {
        $io->title('Babel translation stats');

        $strTable = BabelSchema::STRING_TABLE;          // "str"
        $trTable  = BabelSchema::TRANSLATION_TABLE;     // "str_tr"

        // --- STR total ---
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

        // --- STR_TR total ---
        try {
            $params = [];
            $sqlTrTotal = "SELECT COUNT(*) FROM {$trTable}";
            if ($locale !== null) {
                $sqlTrTotal .= " WHERE " . BabelSchema::TR_TARGET_LOCALE . " = :locale";
                $params['locale'] = $locale;
            }

            $totalTr = (int) $this->connection->fetchOne($sqlTrTotal, $params);
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'Failed to query STR_TR table "%s": %s',
                $trTable,
                $e->getMessage()
            ));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('STR rows (%s): <info>%d</info>', $strTable, $totalStr));
        $io->writeln(sprintf(
            'STR_TR rows (%s%s): <info>%d</info>',
            $trTable,
            $locale ? sprintf(', target_locale="%s"', $locale) : '',
            $totalTr
        ));
        $io->newLine();

        // --- Per-locale aggregation ---
        $params = [];
        $sql = sprintf(
            "
            SELECT
                %s AS locale,
                COUNT(*) AS total,
                SUM(CASE WHEN %s IS NOT NULL AND %s <> '' THEN 1 ELSE 0 END) AS translated
            FROM %s
            ",
            BabelSchema::TR_TARGET_LOCALE,
            BabelSchema::TR_TEXT,
            BabelSchema::TR_TEXT,
            $trTable
        );

        if ($locale !== null) {
            $sql .= " WHERE " . BabelSchema::TR_TARGET_LOCALE . " = :locale";
            $params['locale'] = $locale;
        }

        $sql .= " GROUP BY " . BabelSchema::TR_TARGET_LOCALE . " ORDER BY " . BabelSchema::TR_TARGET_LOCALE;

        try {
            /** @var array<int, array{locale:string,total:string,translated:string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to aggregate per-locale stats: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($rows === []) {
            $io->warning($locale
                ? sprintf('No rows found in %s for target_locale "%s".', $trTable, $locale)
                : sprintf('No rows found in %s. Have you flushed any translatable entities yet?', $trTable)
            );

            if ($terms) {
                $this->renderTermsOverview($io);
            }

            return Command::SUCCESS;
        }

        $io->section('Per-locale coverage');

        $header = ['Locale', 'Rows', 'With text', 'Missing', 'Coverage vs STR'];
        if (!$noBars) {
            $header[] = 'Bar';
        }

        $tableRows = [];
        foreach ($rows as $r) {
            $loc        = (string) $r['locale'];
            $total      = (int) $r['total'];
            $translated = (int) $r['translated'];
            $missing    = max(0, $total - $translated);

            $coverage = ($totalStr > 0)
                ? (100.0 * $translated / $totalStr)
                : 0.0;

            $row = [
                $loc,
                (string) $total,
                (string) $translated,
                (string) $missing,
                sprintf('%.1f%%', $coverage),
            ];

            if (!$noBars) {
                $row[] = $this->coverageBar($coverage);
            }

            $tableRows[] = $row;
        }

        $io->table($header, $tableRows);
        $io->note('Coverage is computed as: translated STR_TR rows / STR rows, per target locale.');

        if ($terms) {
            $io->newLine();
            $this->renderTermsOverview($io);
        }

        return Command::SUCCESS;
    }

    private function renderTermsOverview(SymfonyStyle $io): void
    {
        $termTable = BabelSchema::TERM_TABLE;
        $setTable  = BabelSchema::TERM_SET_TABLE;

        try {
            $setCount  = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$setTable}");
            $termCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$termTable}");
        } catch (\Throwable $e) {
            $io->warning(sprintf('Terms overview unavailable: %s', $e->getMessage()));
            return;
        }

        $io->section('Terms overview');
        $io->writeln(sprintf('TermSets (%s): <info>%d</info>', $setTable, $setCount));
        $io->writeln(sprintf('Terms (%s): <info>%d</info>', $termTable, $termCount));

        $sql = "
            SELECT s.code AS set_code, COUNT(t.id) AS term_count
            FROM term_set s
            LEFT JOIN term t ON t.term_set_id = s.id
            GROUP BY s.code
            ORDER BY s.code
        ";

        try {
            /** @var array<int, array{set_code:string,term_count:string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql);
        } catch (\Throwable) {
            return;
        }

        if ($rows !== []) {
            $io->table(
                ['TermSet', 'Terms'],
                array_map(static fn(array $r) => [$r['set_code'], $r['term_count']], $rows)
            );
        }
    }

    private function coverageBar(float $pct, int $width = 24): string
    {
        $pct = max(0.0, min(100.0, $pct));
        $filled = (int) round($pct / 100 * $width);
        $empty  = $width - $filled;

        return '[' . str_repeat('#', $filled) . str_repeat('.', $empty) . ']';
    }
}
