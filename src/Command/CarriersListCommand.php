<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\CarrierRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:carriers', 'List discovered carriers with #[BabelStorage] grouped by storage mode.')]
final class CarriersListCommand
{
    public function __construct(private readonly CarrierRegistry $registry) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $all = $this->registry->listCarriers();

        $io->section('Code-mode carriers');
        foreach ($all['code'] as $fqcn) {
            $io->writeln(" - $fqcn");
        }

        $io->section('Property-mode carriers');
        foreach ($all['property'] as $fqcn) {
            $io->writeln(" - $fqcn");
        }

        return 0;
    }
}
