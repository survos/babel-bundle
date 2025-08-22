<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:debug', 'Show the compile-time translatable index built by the compiler pass.')]
final class TranslatableIndexCommand
{
    public function __construct(private readonly TranslatableIndex $index) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $all = $this->index->all();

        if (!$all) {
            $io->warning('No translatable entities found in the compile-time index.');
            return Command::SUCCESS;
        }

        $io->section('Compile-time translatable index');
        foreach ($all as $class => $info) {
            $io->writeln(" <info>$class</info>");
            if (!empty($info['fields'])) {
                foreach ($info['fields'] as $name => $meta) {
                    $ctx = $meta['context'] ?? '';
                    $io->writeln("   - $name" . ($ctx ? " (context=$ctx)" : ''));
                }
            }
            if (!empty($info['localeProp'])) {
                $io->writeln("   localeProp: ".$info['localeProp']);
            }
            if (!empty($info['needsHooks'])) {
                $io->writeln("   needsHooks: yes".(
                    !empty($info['fieldsNeedingHooks']) ? " (".\implode(', ', $info['fieldsNeedingHooks']).")" : ''
                ));
            }
        }

        return Command::SUCCESS;
    }
}
