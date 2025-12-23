<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:stats', 'Show translation statistics for Babel STR/STR_TR (per-locale coverage + engine breakdown).')]
final class BabelStatsCommand
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter stats to a specific target locale (e.g. "es").')]
        ?string $locale = null,
        #[Option('Filter stats to a specific TR engine (default: "babel"). Use "(all)" to disable.')]
        string $engine = 'babel',
        #[Option('Disable ASCII coverage bars in the output.')]
        bool $noBars = false,
    ): int {
        $io->title('Babel translation stats');

        $strTable = BabelSchema::STR_TABLE;
        $trTable  = BabelSchema::STR_TR_TABLE;

        $totalStr = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$strTable}");
        $io->writeln(sprintf('STR rows (%s): <info>%d</info>', $strTable, $totalStr));

        $params = [];
        $where = [];

        if ($locale !== null) {
            $where[] = BabelSchema::STR_TR_TARGET_LOCALE.' = :locale';
            $params['locale'] = $locale;
        }

        $engineFilterEnabled = $engine !== '(all)';
        if ($engineFilterEnabled) {
            $where[] = BabelSchema::STR_TR_ENGINE.' = :engine';
            $params['engine'] = $engine;
        }

        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        $totalTr = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$trTable} {$whereSql}", $params);
        $io->writeln(sprintf(
            'STR_TR rows (%s%s%s): <info>%d</info>',
            $trTable,
            $locale ? sprintf(', target_locale="%s"', $locale) : '',
            $engineFilterEnabled ? sprintf(', engine="%s"', $engine) : '',
            $totalTr
        ));
        $io->newLine();

        // Per-locale coverage for selected engine
        $io->section('Per-locale coverage');

        $sql = "
            SELECT
                ".BabelSchema::STR_TR_TARGET_LOCALE." AS locale,
                COUNT(*) AS total,
                SUM(CASE WHEN ".BabelSchema::STR_TR_TEXT." IS NOT NULL AND ".BabelSchema::STR_TR_TEXT." <> '' THEN 1 ELSE 0 END) AS translated
            FROM {$trTable}
            {$whereSql}
            GROUP BY ".BabelSchema::STR_TR_TARGET_LOCALE."
            ORDER BY ".BabelSchema::STR_TR_TARGET_LOCALE."
        ";

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        if ($rows === []) {
            $io->warning('No STR_TR rows match the current filters.');
            return Command::SUCCESS;
        }

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

            $coverage = ($totalStr > 0) ? (100.0 * $translated / $totalStr) : 0.0;

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

        // Engine breakdown (always useful)
        $io->newLine();
        $io->section('Engine breakdown');

        $params2 = [];
        $where2 = [];
        if ($locale !== null) {
            $where2[] = BabelSchema::STR_TR_TARGET_LOCALE.' = :locale';
            $params2['locale'] = $locale;
        }
        $whereSql2 = $where2 ? ('WHERE '.implode(' AND ', $where2)) : '';

        $sql2 = "
            SELECT
                ".BabelSchema::STR_TR_TARGET_LOCALE." AS locale,
                COALESCE(".BabelSchema::STR_TR_ENGINE.", '(null)') AS engine,
                COUNT(*) AS total,
                SUM(CASE WHEN ".BabelSchema::STR_TR_TEXT." IS NOT NULL AND ".BabelSchema::STR_TR_TEXT." <> '' THEN 1 ELSE 0 END) AS translated
            FROM {$trTable}
            {$whereSql2}
            GROUP BY ".BabelSchema::STR_TR_TARGET_LOCALE.", ".BabelSchema::STR_TR_ENGINE."
            ORDER BY ".BabelSchema::STR_TR_TARGET_LOCALE.", engine
        ";

        $rows2 = $this->connection->fetchAllAssociative($sql2, $params2);
        if ($rows2) {
            $io->table(
                ['Locale', 'Engine', 'Rows', 'With text', 'Missing'],
                array_map(static function(array $r): array {
                    $total = (int) $r['total'];
                    $translated = (int) $r['translated'];
                    return [
                        (string) $r['locale'],
                        (string) $r['engine'],
                        (string) $total,
                        (string) $translated,
                        (string) max(0, $total - $translated),
                    ];
                }, $rows2)
            );
        }

        return Command::SUCCESS;
    }

    private function coverageBar(float $pct, int $width = 24): string
    {
        $pct = max(0.0, min(100.0, $pct));
        $filled = (int) round($pct / 100 * $width);
        $empty  = $width - $filled;
        return '[' . str_repeat('#', $filled) . str_repeat('.', $empty) . ']';
    }
}
