<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\TranslatableMapProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand('babel:debug', 'Inspect Babel carriers, translatable fields, cache, and compiler-pass parameters.')]
final class BabelDebugCommand
{
    public function __construct(
        private readonly CarrierRegistry $carriers,
        private readonly TranslatableScanner $scanner,
        private readonly TranslatableMapProvider $provider,
        private readonly ParameterBagInterface $params,
        private readonly ManagerRegistry $doctrine,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('carriers', 'Show discovered carriers (by storage mode).')] bool $showCarriers = false,
        #[Option('scan', 'Scan and show live translatable fields (property mode).')] bool $showScan = false,
        #[Option('cache', 'Show cached translatable map (from cache warmup).')] bool $showCache = false,
        #[Option('params', 'Show compiler-pass parameters (EMs & namespaces).')] bool $showParams = false,
        #[Option('class', 'Filter results to a specific FQCN.')] ?string $class = null,
        #[Option('em', 'Limit scan to a single entity manager name.')] ?string $em = null,
    ): int {
        $any = $showCarriers || $showScan || $showCache || $showParams;
        if (!$any) {
            $showCarriers = $showScan = $showCache = $showParams = true;
        }

        if ($showParams) {
            $io->section('Compiler-pass parameters');
            $ems = $this->params->get('survos_babel.scan_entity_managers') ?? [];
            $namespaces = $this->params->get('survos_babel.allowed_namespaces') ?? [];
            $io->listing([
                'scan_entity_managers: ' . json_encode($ems, JSON_UNESCAPED_SLASHES),
                'allowed_namespaces: ' . json_encode($namespaces, JSON_UNESCAPED_SLASHES),
            ]);
            if ($em) {
                $io->writeln(sprintf('<info>Using --em=%s to filter live scans below.</info>', $em));
            }
        }

        if ($showCarriers) {
            $io->section('Carriers (#[BabelStorage])');
            $found = $this->carriers->listCarriers();
            $filter = static fn(string $fqcn): bool => !$class || \strcasecmp($fqcn, $class) === 0;

            $io->writeln('<comment>Code-mode carriers</comment>');
            foreach (array_filter($found['code'], $filter) as $fqcn) {
                $io->writeln(" - $fqcn");
            }

            $io->writeln('<comment>Property-mode carriers</comment>');
            foreach (array_filter($found['property'], $filter) as $fqcn) {
                $io->writeln(" - $fqcn");
            }
        }

        if ($showScan) {
            $io->section('Live scan (property-mode translatable fields via #[Translatable])');

            // Optionally restrict scanner to a single EM by temporarily swapping parameter at runtime
            $map = $this->scanner->buildMap();
            if ($class) {
                $map = array_intersect_key($map, [$class => true]);
            }

            if (!$map) {
                $io->warning('No translatable fields found. Are your entities tagged with #[BabelStorage(Property)] and properties with #[Translatable]?');
            } else {
                foreach ($map as $fqcn => $fields) {
                    $io->writeln(" <info>$fqcn</info>");
                    foreach ($fields as $f) {
                        $io->writeln("   - $f");
                    }
                }
            }
        }

        if ($showCache) {
            $io->section('Cached translatable map (cache.app)');
            $cached = $this->provider->get();
            if ($class) {
                $cached = array_intersect_key($cached, [$class => true]);
            }

            $totalClasses = \count($cached);
            $totalFields  = array_sum(array_map('count', $cached));
            $io->writeln(sprintf('Classes: %d, Fields: %d', $totalClasses, $totalFields));

            foreach ($cached as $fqcn => $fields) {
                $io->writeln(" <info>$fqcn</info>");
                foreach ($fields as $f) {
                    $io->writeln("   - $f");
                }
            }
            $io->writeln('');
            $io->note('Rebuild cache with: bin/console cache:warmup');
        }

        return Command::SUCCESS;
    }
}
