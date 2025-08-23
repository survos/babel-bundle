<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:debug:schema', 'Show Doctrine table/column mapping for Str / StrTranslation')]
final class BabelDebugSchemaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('FQCN for Str entity')] string $strClass = 'Survos\\BabelBundle\\Entity\\Str',
        #[Option('FQCN for StrTranslation entity')] string $trClass  = 'Survos\\BabelBundle\\Entity\\StrTranslation',
    ): int {
        $verbose = $io->isVerbose();
        $io->title('Babel DB schema (Doctrine metadata)');

        foreach ([['name'=>'Str', 'class'=>$strClass], ['name'=>'StrTranslation', 'class'=>$trClass]] as $row) {
            $name  = $row['name'];
            $class = $row['class'];

            if (!class_exists($class)) {
                $io->error(sprintf('%s class not found: %s', $name, $class));
                continue;
            }

            $meta = $this->em->getClassMetadata($class);
            $io->section(sprintf('%s: %s', $name, $class));

            $io->writeln(sprintf('Table: <info>%s</info>', $meta->getTableName()));
            $io->writeln(sprintf('Identifier(s): <comment>%s</comment>', implode(', ', array_keys($meta->getIdentifierFieldNames()))));

            // Columns table
            $rows = [];
            foreach ($meta->getFieldNames() as $field) {
                $mapping    = $meta->getFieldMapping($field);
                $columnName = $meta->getColumnName($field);
                $type       = $mapping['type'] ?? 'unknown';
                $nullable   = ($mapping['nullable'] ?? false) ? 'YES' : 'NO';
                $length     = $mapping['length'] ?? '';
                $options    = $mapping['options'] ?? [];
                $rows[] = [
                    $field,
                    $columnName,
                    (string)$type,
                    $nullable,
                    (string)$length,
                    $options ? json_encode($options, JSON_UNESCAPED_SLASHES) : '',
                ];
            }

            if ($rows) {
                $io->table(
                    ['Field', 'Column', 'Type', 'Nullable', 'Length', 'Options'],
                    $rows
                );
            } else {
                $io->warning('No mapped scalar fields? (Check your entity mapping.)');
            }

            // Association columns (rare here, but helpful)
            $assocRows = [];
            foreach ($meta->getAssociationMappings() as $field => $m) {
                $assocRows[] = [
                    $field,
                    $m['targetEntity'] ?? '',
                    $m['type'] ?? '',
                    !empty($m['joinColumns']) ? json_encode($m['joinColumns']) : '',
                ];
            }
            if ($assocRows) {
                $io->table(['Assoc field', 'Target', 'Type', 'Join columns'], $assocRows);
            }

            if ($verbose) {
                $io->writeln('<info>Raw mapping:</info>');
                $io->writeln(print_r([
                    'table'   => $meta->getTableName(),
                    'fields'  => array_map(fn($f)=>$meta->getFieldMapping($f), $meta->getFieldNames()),
                    'id'      => $meta->getIdentifierFieldNames(),
                    'assocs'  => $meta->getAssociationMappings(),
                ], true));
            }
        }

        // Sanity checks tailored to Babel
        $io->section('Babel sanity checks');

        $problems = [];

        // Str requirements
        try {
            $m = $this->em->getClassMetadata($strClass);
            foreach (['hash','original','srcLocale'] as $required) {
                if (!$m->hasField($required)) {
                    $problems[] = sprintf('Str missing field "%s" (expected in mapping).', $required);
                }
            }
            // Optional timestamps should be mapped if NOT NULL in DB
            foreach (['createdAt','updatedAt'] as $ts) {
                if ($m->hasField($ts)) {
                    $nullable = $m->getFieldMapping($ts)['nullable'] ?? true;
                    if ($nullable === false) {
                        // ok — our upsert logic will set NOW()/CURRENT_TIMESTAMP
                    }
                }
            }
        } catch (\Throwable $e) {
            $problems[] = 'Failed reading Str metadata: '.$e->getMessage();
        }

        // StrTranslation requirements
        try {
            $m = $this->em->getClassMetadata($trClass);
            foreach (['hash','locale','text'] as $required) {
                if (!$m->hasField($required)) {
                    $problems[] = sprintf('StrTranslation missing field "%s" (expected in mapping).', $required);
                }
            }
            // If "text" is NOT NULL in DB, ensure your ensure-insert uses '' not NULL
            if ($m->hasField('text')) {
                $nullable = $m->getFieldMapping('text')['nullable'] ?? false;
                if ($nullable === false) {
                    $io->note('Detected StrTranslation.text NOT NULL — ensure your ensure-insert uses empty string ("") not NULL.');
                }
            }
        } catch (\Throwable $e) {
            $problems[] = 'Failed reading StrTranslation metadata: '.$e->getMessage();
        }

        if ($problems) {
            $io->error("Problems detected:\n- " . implode("\n- ", $problems));
            return 1;
        }

        $io->success('Doctrine mapping looks sane for Str / StrTranslation.');
        return 0;
    }
}
