<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:termset', 'Browse a TermSet and show term labels (translated via STR_TR)')]
final class BabelTermSetCommand
{
    public function __construct(
        private readonly Connection $db,
        private readonly LocaleContext $localeContext,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('TermSet code (e.g. category, tag)')] ?string $set = null,
        #[Option('Display locale (defaults to current request/Context)', shortcut: 'l')] ?string $locale = null,
        #[Option('Engine for str_tr lookup (default: babel)')] string $engine = 'babel',
        #[Option('Max rows to show (0 = all)')] int $limit = 200,
        #[Option('Filter by term code prefix')] ?string $startsWith = null,
    ): int {
        if ($locale) {
            $this->localeContext->set($locale);
        }
        $displayLocale = $this->localeContext->get();

        if (!$set) {
            $sql = sprintf(
                'SELECT s.%s AS set_code, COUNT(t.%s) AS term_count
                 FROM %s s
                 LEFT JOIN %s t ON t.%s = s.%s
                 GROUP BY s.%s
                 ORDER BY s.%s',
                BabelSchema::TERM_SET_CODE,
                BabelSchema::TERM_ID,
                BabelSchema::TERM_SET_TABLE,
                BabelSchema::TERM_TABLE,
                BabelSchema::TERM_TERM_SET_ID,
                BabelSchema::TERM_SET_ID,
                BabelSchema::TERM_SET_CODE,
                BabelSchema::TERM_SET_CODE
            );

            $sets = $this->db->fetchAllAssociative($sql);

            if (!$sets) {
                $io->error('No TermSets found.');
                return Command::INVALID;
            }

            $io->section('Available TermSets');
            $io->table(
                ['Code', 'Terms'],
                array_map(
                    fn(array $r) => [(string) $r['set_code'], (string) $r['term_count']],
                    $sets
                )
            );

            if (!$input->isInteractive()) {
                $io->error('Missing <set> argument (non-interactive mode).');
                return Command::INVALID;
            }

            $choices = array_map(fn(array $r) => (string) $r['set_code'], $sets);
            $set = $io->choice('Which TermSet do you want to browse?', $choices);
        }

        $setId = $this->db->fetchOne(
            sprintf(
                'SELECT s.%s FROM %s s WHERE s.%s = :c LIMIT 1',
                BabelSchema::TERM_SET_ID,
                BabelSchema::TERM_SET_TABLE,
                BabelSchema::TERM_SET_CODE
            ),
            ['c' => $set]
        );

        if (!$setId) {
            $io->error(sprintf('Unknown TermSet: %s', $set));
            return Command::INVALID;
        }

        $io->title(sprintf('TermSet "%s" (locale=%s, engine=%s)', $set, $displayLocale, $engine));

        $where = ['t.' . BabelSchema::TERM_TERM_SET_ID . ' = :sid'];
        $params = ['sid' => (int) $setId];

        if ($startsWith) {
            $where[] = 't.' . BabelSchema::TERM_CODE . ' LIKE :sw';
            $params['sw'] = $startsWith . '%';
        }

        $sqlTerms = sprintf(
            'SELECT t.%s AS term_code, t.%s AS term_path, t.%s AS label_code
             FROM %s t
             WHERE %s
             ORDER BY t.%s ASC',
            BabelSchema::TERM_CODE,
            BabelSchema::TERM_PATH,
            BabelSchema::TERM_LABEL_CODE,
            BabelSchema::TERM_TABLE,
            implode(' AND ', $where),
            BabelSchema::TERM_CODE
        );

        if ($limit > 0) {
            $sqlTerms .= ' LIMIT ' . (int) $limit;
        }

        $terms = $this->db->fetchAllAssociative($sqlTerms, $params);

        if (!$terms) {
            $io->warning('No terms found.');
            return Command::SUCCESS;
        }

        $labelCodes = array_values(array_filter(array_map(
            fn(array $r) => $r['label_code'] ? (string) $r['label_code'] : null,
            $terms
        )));

        $trByCode = [];
        if ($labelCodes) {
            $sqlTr = sprintf(
                'SELECT tr.%s AS str_code, tr.%s AS text
                 FROM %s tr
                 WHERE tr.%s IN (:codes)
                   AND tr.%s = :loc
                   AND tr.%s = :eng',
                BabelSchema::STR_TR_STR_CODE,
                BabelSchema::STR_TR_TEXT,
                BabelSchema::STR_TR_TABLE,
                BabelSchema::STR_TR_STR_CODE,
                BabelSchema::STR_TR_TARGET_LOCALE,
                BabelSchema::STR_TR_ENGINE
            );

            $rows = $this->db->executeQuery(
                $sqlTr,
                ['codes' => $labelCodes, 'loc' => $displayLocale, 'eng' => $engine],
                ['codes' => ArrayParameterType::STRING]
            )->fetchAllAssociative();

            foreach ($rows as $r) {
                $trByCode[(string) $r['str_code']] = $r['text'] ? (string) $r['text'] : null;
            }
        }

        $missing = 0;
        $rows = [];
        foreach ($terms as $t) {
            $code = (string) $t['term_code'];
            $path = (string) ($t['term_path'] ?? '');
            $labelCode = $t['label_code'] ? (string) $t['label_code'] : null;

            $label = $code;
            $hasTr = false;

            if ($labelCode) {
                $text = $trByCode[$labelCode] ?? null;
                if ($text !== null && $text !== '') {
                    $label = $text;
                    $hasTr = true;
                }
            }

            if (!$hasTr) {
                $missing++;
            }

            $rows[] = [$code, $label, $path, $labelCode ?? '', $hasTr ? 'yes' : 'no'];
        }

        $io->table(['Code', 'Label', 'Path', 'LabelCode', 'Has translation'], $rows);
        $io->note(sprintf('Missing label translations for %s/%s: %d of %d', $set, $displayLocale, $missing, count($rows)));

        return Command::SUCCESS;
    }
}
