<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:str', 'Browse STR (str) rows with optional STR_TR join')]
final class BabelStrCommand
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Search term (matches code/source/context)')] ?string $q = null,
        #[Option('Filter by source locale')] ?string $sourceLocale = null,
        #[Option('Filter by context substring')] ?string $context = null,
        #[Option('Show translations for this target locale (joins str_tr)')] ?string $trLocale = null,
        #[Option('Engine for translation join', )] string $engine = 'babel',
        #[Option('Max rows to show')] int $limit = 30,
    ): int {
        $io->title('STR browser');

        $where = [];
        $params = [];

        if ($sourceLocale) {
            $where[] = 's.' . BabelSchema::STR_SOURCE_LOCALE . ' = :sl';
            $params['sl'] = $sourceLocale;
        }

        if ($context) {
            $where[] = 's.' . BabelSchema::STR_CONTEXT . ' LIKE :ctx';
            $params['ctx'] = '%' . $context . '%';
        }

        if ($q) {
            $where[] = '(s.' . BabelSchema::STR_CODE . ' LIKE :q OR s.' . BabelSchema::STR_SOURCE . ' LIKE :q OR s.' . BabelSchema::STR_CONTEXT . ' LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $sql = 'SELECT s.' . BabelSchema::STR_CODE . ' AS code,
                       s.' . BabelSchema::STR_SOURCE_LOCALE . ' AS source_locale,
                       s.' . BabelSchema::STR_CONTEXT . ' AS context,
                       s.' . BabelSchema::STR_SOURCE . ' AS source';

        if ($trLocale) {
            $sql .= ', tr.' . BabelSchema::STR_TR_TEXT . ' AS tr_text';
        }

        $sql .= ' FROM ' . BabelSchema::STR_TABLE . ' s';

        if ($trLocale) {
            $sql .= ' LEFT JOIN ' . BabelSchema::STR_TR_TABLE . ' tr
                      ON tr.' . BabelSchema::STR_TR_STR_CODE . ' = s.' . BabelSchema::STR_CODE . '
                     AND tr.' . BabelSchema::STR_TR_TARGET_LOCALE . ' = :tl
                     AND tr.' . BabelSchema::STR_TR_ENGINE . ' = :eng';
            $params['tl'] = $trLocale;
            $params['eng'] = $engine;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY s.' . BabelSchema::STR_CODE . ' ASC';
        $sql .= ' LIMIT ' . (int) $limit;

        $rows = $this->db->fetchAllAssociative($sql, $params);

        if (!$rows) {
            $io->warning('No rows matched.');
            return Command::SUCCESS;
        }

        $headers = array_keys($rows[0]);
        $io->table($headers, array_map(fn(array $r) => array_values($r), $rows));

        $io->note(sprintf(
            'Tip: term labels use contexts like "term:set:code:label" or "term_set:set:label" depending on your registry.',
        ));

        return Command::SUCCESS;
    }
}
