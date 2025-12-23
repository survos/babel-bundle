<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\BabelBundle\Runtime\BabelSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:tr', 'Browse STR_TR (str_tr) rows with filters')]
final class BabelTrCommand
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Search term (matches str_code/text)')] ?string $q = null,
        #[Option('Filter by target locale', shortcut: 'l')] ?string $locale = null,
        #[Option('Filter by engine')] ?string $engine = null,
        #[Option('Only show missing (NULL/empty) text')] bool $missing = false,
        #[Option('Max rows to show')] int $limit = 50,
    ): int {
        $io->title('STR_TR browser');

        $where = [];
        $params = [];

        if ($locale) {
            $where[] = 'tr.' . BabelSchema::STR_TR_TARGET_LOCALE . ' = :loc';
            $params['loc'] = $locale;
        }
        if ($engine) {
            $where[] = 'tr.' . BabelSchema::STR_TR_ENGINE . ' = :eng';
            $params['eng'] = $engine;
        }
        if ($missing) {
            $where[] = '(tr.' . BabelSchema::STR_TR_TEXT . ' IS NULL OR tr.' . BabelSchema::STR_TR_TEXT . " = '')";
        }
        if ($q) {
            $where[] = '(tr.' . BabelSchema::STR_TR_STR_CODE . ' LIKE :q OR tr.' . BabelSchema::STR_TR_TEXT . ' LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $sql = 'SELECT tr.' . BabelSchema::STR_TR_STR_CODE . ' AS str_code,
                       tr.' . BabelSchema::STR_TR_TARGET_LOCALE . ' AS target_locale,
                       tr.' . BabelSchema::STR_TR_ENGINE . ' AS engine,
                       tr.' . BabelSchema::STR_TR_TEXT . ' AS text
                FROM ' . BabelSchema::STR_TR_TABLE . ' tr';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY tr.' . BabelSchema::STR_TR_STR_CODE . ' ASC';
        $sql .= ' LIMIT ' . (int) $limit;

        $rows = $this->db->fetchAllAssociative($sql, $params);
        if (!$rows) {
            $io->warning('No rows matched.');
            return Command::SUCCESS;
        }

        $io->table(array_keys($rows[0]), array_map(fn(array $r) => array_values($r), $rows));
        return Command::SUCCESS;
    }
}
