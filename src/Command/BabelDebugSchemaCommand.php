<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:debug:schema', 'Show Doctrine table/column mapping for Str / StrTranslation (by table name)')]
final class BabelDebugSchemaCommand
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Table name for Str (default: str)')] string $strTable = 'str',
        #[Option('Table name for StrTranslation (default: str_translation)')] string $trTable = 'str_translation',
    ): int {
        $io->title('Babel DB schema (Doctrine metadata)');

        $all = $this->em->getMetadataFactory()->getAllMetadata();
        $byTable = [];
        foreach ($all as $m) {
            $byTable[$m->getTableName()][] = $m;
        }

        $targets = [
            ['name'=>'Str', 'table'=>$strTable],
            ['name'=>'StrTranslation', 'table'=>$trTable],
        ];

        foreach ($targets as $t) {
            $name  = $t['name'];
            $table = $t['table'];

            $io->section(sprintf('%s table: %s', $name, $table));

            $metas = $byTable[$table] ?? [];
            if (!$metas) {
                $io->error(sprintf('No Doctrine entity maps to table "%s".', $table));
                continue;
            }
            if (\count($metas) > 1) {
                $io->warning(sprintf('Multiple entities map table "%s": %s', $table, implode(', ', array_map(fn($m)=>$m->getName(), $metas))));
            }

            $m = $metas[0];

            $io->writeln(sprintf('Entity: <info>%s</info>', $m->getName()));
            $io->writeln(sprintf('Identifiers: <comment>%s</comment>', implode(', ', $m->getIdentifierFieldNames())));

            $rows = [];
            foreach ($m->getFieldNames() as $field) {
                $map = $m->getFieldMapping($field);
                $rows[] = [
                    $field,
                    $m->getColumnName($field),
                    (string)($map['type'] ?? 'unknown'),
                    ($map['nullable'] ?? false) ? 'YES' : 'NO',
                    (string)($map['length'] ?? ''),
                    isset($map['options']) ? json_encode($map['options'], JSON_UNESCAPED_SLASHES) : '',
                ];
            }
            $io->table(['Field','Column','Type','Nullable','Length','Options'], $rows);

            if ($io->isVerbose()) {
                $io->writeln('<info>Raw:</info>');
                $io->writeln(print_r([
                    'entity'  => $m->getName(),
                    'table'   => $m->getTableName(),
                    'fields'  => array_map(fn($f)=>$m->getFieldMapping($f), $m->getFieldNames()),
                    'id'      => $m->getIdentifierFieldNames(),
                    'assocs'  => $m->getAssociationMappings(),
                ], true));
            }
        }

        $io->success('Done.');
        return 0;
    }
}
