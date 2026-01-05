<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Service\TranslatableMapProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'babel:debug',
    description: 'Inspect Babel carriers, translatable fields, locales, cache, and compiler-pass parameters.'
)]
final class BabelDebugCommand
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
        private readonly LocaleContext $localeContext,
        private readonly TranslatableScanner $scanner,
        private readonly TranslatableIndex $translatableIndex,
        // Keep injected for future; do not call unknown API.
        private readonly TranslatableMapProvider $mapProvider,
        /** @var list<string> */
        private readonly array $scanEntityManagers = ['default'],
        /** @var list<string> */
        private readonly array $allowedNamespaces = ['App\\Entity'],
        /** @var list<string> */
        private readonly array $scanRoots = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Show discovered carriers (by storage mode).')]
        bool $carriers = false,

        #[Option('Scan and show live translatable fields (property mode).')]
        bool $scan = false,

        #[Option('Show cached translatable map (from cache warmup).')]
        bool $cache = false,

        #[Option('Show compiler-pass parameters (EMs & namespaces).')]
        bool $params = false,

        #[Option('Show compile-time translatable index (BabelTraitAwareScanPass).')]
        bool $index = false,

        #[Option('Show Babel/translator locales.')]
        bool $locales = false,

        #[Option('Filter results to a specific FQCN.')]
        ?string $class = null,

        #[Option('Limit scan to a single entity manager name (for scanner).')]
        ?string $em = null,
    ): int {
        // If no flags specified, show a useful overview.
        if (!$carriers && !$scan && !$cache && !$params && !$index && !$locales) {
            $params = $locales = $carriers = $index = $scan = $cache = true;
        }

        if ($params) {
            $io->section('Compiler-pass parameters');
            $io->writeln(sprintf(' * scan_entity_managers: %s', json_encode($this->scanEntityManagers)));
            $io->writeln(sprintf(' * allowed_namespaces:   %s', json_encode($this->allowedNamespaces)));
            $io->writeln(sprintf(' * scan_roots:           %s', json_encode($this->scanRoots)));
        }

        if ($locales) {
            $io->section('Locales');
            $io->writeln(sprintf(' * Babel default locale:   %s', $this->localeContext->getDefault()));
            $io->writeln(sprintf(' * Babel enabled locales:  %s', json_encode($this->localeContext->getEnabled())));
            $io->writeln(sprintf(' * Babel current locale:   %s', $this->localeContext->get()));
        }

        if ($carriers) {
            $io->section('Carriers (#[BabelStorage])');

            $carriersByMode = $this->carrierRegistry->listCarriers();

            $io->writeln('Code-mode carriers');
            foreach ($carriersByMode['code'] as $fqcn) {
                if ($class && $fqcn !== $class) {
                    continue;
                }
                $io->writeln(' - ' . $fqcn);
            }

            $io->writeln('Property-mode carriers');
            foreach ($carriersByMode['property'] as $fqcn) {
                if ($class && $fqcn !== $class) {
                    continue;
                }
                $io->writeln(' - ' . $fqcn);
            }
        }

        if ($index) {
            $io->section('Compile-time translatable index (survos_babel.translatable_index)');

            $all = $this->translatableIndex->all();
            foreach ($all as $fqcn => $def) {
                if ($class && $fqcn !== $class) {
                    continue;
                }

                $io->writeln("\n " . $fqcn);

                $fields = $def['fields'] ?? [];
                $io->writeln('   Fields: ' . json_encode($fields));

                if (array_key_exists('sourceLocale', $def)) {
                    $io->writeln('   sourceLocale: ' . json_encode($def['sourceLocale']));
                }
                if (array_key_exists('targetLocales', $def)) {
                    $io->writeln('   targetLocales: ' . json_encode($def['targetLocales']));
                }
                if (array_key_exists('localeAccessor', $def)) {
                    $io->writeln('   localeAccessor: ' . json_encode($def['localeAccessor']));
                }
            }
        }

        if ($scan) {
            $io->section('Live scan (property-mode translatable fields via #[Translatable])');

            if ($em !== null) {
                $io->note(sprintf(
                    'Option --em=%s is currently ignored: TranslatableScanner is configured via constructor (scan_entity_managers).',
                    $em
                ));
            }

            $map = $this->scanner->buildMap();

            if ($class) {
                $map = array_key_exists($class, $map) ? [$class => $map[$class]] : [];
            }

            foreach ($map as $fqcn => $fields) {
                $io->writeln("\n " . $fqcn);
                foreach ($fields as $field) {
                    $io->writeln('   - ' . $field);
                }
            }
        }

        if ($cache) {
            $io->section('Cached translatable map (cache.app via TranslatableMapProvider)');
            $io->note('Cache-map API is intentionally not surfaced here. Use: bin/console babel:translatable:dump');
        }

        return Command::SUCCESS;
    }
}
