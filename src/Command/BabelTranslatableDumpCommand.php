<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:translatable:dump', 'Dump the cached translatable index map (debug)')]
final class BabelTranslatableDumpCommand
{
    public function __construct(private readonly TranslatableIndex $index) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Cached translatable index map');
        $map = $this->index->all();
        if (!$map) {
            $io->warning('Index map is empty.');
            return 0;
        }

        foreach ($map as $class => $cfg) {
            $io->writeln("<info>$class</info>");
            $fields = array_keys($cfg['fields'] ?? []);
            foreach ($fields as $f) {
                $ctx = $cfg['fields'][$f]['context'] ?? null;
                $io->writeln('  - '.$f.($ctx ? " (ctx: $ctx)" : ''));
            }
            if (!empty($cfg['localeProp'])) {
                $io->writeln('  localeProp: '.$cfg['localeProp']);
            }
            if (!empty($cfg['hasTCodes'])) {
                $io->writeln('  hasTCodes: yes');
            }
            $io->newLine();
        }

        // Optionally, raw JSON:
        // $io->writeln(json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
