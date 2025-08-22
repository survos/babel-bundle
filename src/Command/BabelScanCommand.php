<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\TranslatableMapProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:scan', 'Scan entities for #[Translatable] fields (property storage) and show the map.')]
final class BabelScanCommand
{
    public function __construct(
        private readonly TranslatableScanner $scanner,
        private readonly TranslatableMapProvider $provider,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $map = $this->scanner->buildMap();
        if (!$map) {
            $io->warning('No translatable fields found. Did you add #[BabelStorage(Property)] and #[Translatable] attributes?');
            return Command::SUCCESS;
        }

        $io->section('Translatable fields by class');
        foreach ($map as $class => $fields) {
            $io->writeln(" <info>$class</info>");
            foreach ($fields as $f) {
                $io->writeln("   - $f");
            }
        }

        $io->note('Map is also cached via cache.app; warm it with bin/console cache:warmup');
        // touch the provider to prove cache path works
        $cached = $this->provider->get();
        $io->writeln(sprintf('Cached entries: %d', \count($cached)));

        return Command::SUCCESS;
    }
}
