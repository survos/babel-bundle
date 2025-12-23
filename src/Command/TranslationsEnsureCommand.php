<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\Lingua\Core\Identity\HashUtil;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:ensure', 'Ensure (str.code, target_locale) rows exist in str_tr for target locale(s)')]
final class TranslationsEnsureCommand
{
    public function __construct(
        private readonly Connection $db,
        private readonly LocaleContext $localeContext,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument('Target locales (comma-delimited) or empty to use enabled_locales')] ?string $localesArg = null,
        #[Option('Filter by STR.source_locale')] ?string $sourceLocale = null,
        #[Option('Filter by STR.context substring (e.g. "term:" or "pixie:cleveland")')] ?string $context = null,

        #[Option('Provider engine value for STR_TR rows (optional; NOT a stub marker).')]
        ?string $engine = null,

        #[Option('Initial status for inserted STR_TR rows (enum-ready).')]
        string $status = 'new',

        #[Option('Extra meta (JSON object) merged into stub meta.')]
        ?string $meta = null,

        #[Option('Limit STR rows scanned per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
    ): int {
        $targets = $this->parseLocalesArg($localesArg);

        if ($targets === []) {
            $targets = $this->localeContext->getEnabled();
            if ($targets === []) {
                $targets = [$this->localeContext->getDefault()];
            }
        }

        $targets = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $targets)));
        $targets = array_values(array_filter($targets, static fn(string $l) => $l !== ''));

        if ($targets === []) {
            $io->error('No target locales resolved.');
            return Command::INVALID;
        }

        $extraMeta = [];
        if ($meta !== null && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            if (!is_array($decoded)) {
                $io->error('Invalid --meta JSON. Must be a JSON object.');
                return Command::INVALID;
            }
            $extraMeta = $decoded;
        }

        $io->title('Babel ensure');
        $io->writeln('Targets: ' . json_encode($targets, JSON_UNESCAPED_SLASHES));
        $io->writeln('Engine:  ' . ($engine ?? '(null)'));
        $io->writeln('Status:  ' . $status);

        $filters = [];
        $paramsBase = [
            'eng' => $engine, // may be null
            'st'  => $status,
        ];

        if ($sourceLocale !== null && trim($sourceLocale) !== '') {
            $paramsBase['sl'] = HashUtil::normalizeLocale($sourceLocale);
            $filters[] = 's.' . BabelSchema::STR_SOURCE_LOCALE . ' = :sl';
        }

        if ($context !== null && trim($context) !== '') {
            $paramsBase['ctx'] = '%' . $context . '%';
            $filters[] = 's.' . BabelSchema::STR_CONTEXT . ' LIKE :ctx';
        }

        $filterSql = $filters ? (' AND ' . implode(' AND ', $filters)) : '';

        $totalCreated = 0;

        foreach ($targets as $loc) {
            $params = $paramsBase + ['loc' => $loc];

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $candidateCount = (int) $this->db->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s s WHERE s.%s <> :loc%s',
                    BabelSchema::STR_TABLE,
                    BabelSchema::STR_SOURCE_LOCALE,
                    $filterSql
                ), $params);

                $existingTr = (int) $this->db->fetchOne(sprintf(
                    'SELECT COUNT(*) FROM %s tr WHERE tr.%s = :loc',
                    BabelSchema::STR_TR_TABLE,
                    BabelSchema::STR_TR_TARGET_LOCALE
                ), $params);

                $io->writeln(sprintf('locale=%s: candidates=%d existing_tr=%d', $loc, $candidateCount, $existingTr));
            }

            if ($dryRun) {
                $io->writeln(sprintf('locale=%s: DRY-RUN (no inserts)', $loc));
                continue;
            }

            $stubMeta = array_merge([
                'createdBy' => 'babel:ensure',
                'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'targetLocale' => $loc,
                'filters' => array_filter([
                    'sourceLocale' => $paramsBase['sl'] ?? null,
                    'context' => $context ?? null,
                ]),
            ], $extraMeta);

            $metaJson = json_encode($stubMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($metaJson)) {
                $metaJson = '{}';
            }

            // IMPORTANT: if your schema has a UNIQUE on (str_code,target_locale) this is correct.
            // If your schema uniqueness includes engine, you should migrate it; otherwise duplicates remain possible.
            $insertSql = sprintf(
                'INSERT OR IGNORE INTO %s (%s, %s, %s, %s, %s, %s)
                 SELECT s.%s, :loc, :eng, NULL, :st, :meta
                 FROM %s s
                 WHERE s.%s <> :loc%s%s',
                BabelSchema::STR_TR_TABLE,
                BabelSchema::STR_TR_STR_CODE,
                BabelSchema::STR_TR_TARGET_LOCALE,
                BabelSchema::STR_TR_ENGINE,
                BabelSchema::STR_TR_TEXT,
                BabelSchema::STR_TR_STATUS,
                BabelSchema::STR_TR_META,
                BabelSchema::STR_CODE,
                BabelSchema::STR_TABLE,
                BabelSchema::STR_SOURCE_LOCALE,
                $filterSql,
                $limit > 0 ? (' LIMIT ' . (int) $limit) : ''
            );

            $affected = (int) $this->db->executeStatement($insertSql, $params + ['meta' => $metaJson]);
            $totalCreated += $affected;

            $io->writeln(sprintf('locale=%s: created %d', $loc, $affected));
        }

        $io->success(sprintf('Done → STR_TR created %d', $totalCreated));
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
}
