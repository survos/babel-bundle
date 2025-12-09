<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Service\TranslatableMapProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    'babel:debug',
    'Inspect Babel carriers, translatable fields, locales, cache, and compiler-pass parameters.'
)]
final class BabelDebugCommand
{
    public function __construct(
        private readonly CarrierRegistry $carriers,
        private readonly TranslatableScanner $scanner,
        private readonly TranslatableMapProvider $provider,
        private readonly ParameterBagInterface $params,
        private readonly ManagerRegistry $doctrine,
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $localeContext,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('carriers', 'Show discovered carriers (by storage mode).')]
        bool $showCarriers = false,
        #[Option('scan', 'Scan and show live translatable fields (property mode).')]
        bool $showScan = false,
        #[Option('cache', 'Show cached translatable map (from cache warmup).')]
        bool $showCache = false,
        #[Option('params', 'Show compiler-pass parameters (EMs & namespaces).')]
        bool $showParams = false,
        #[Option('index', 'Show compile-time translatable index (BabelTraitAwareScanPass).')]
        bool $showIndex = false,
        #[Option('locales', 'Show Babel/translator locales.')]
        bool $showLocales = false,
        #[Option('class', 'Filter results to a specific FQCN.')]
        ?string $class = null,
        #[Option('em', 'Limit scan to a single entity manager name (for scanner).')]
        ?string $em = null,
    ): int {
        $any = $showCarriers || $showScan || $showCache || $showParams || $showIndex || $showLocales;
        if (!$any) {
            $showCarriers = $showScan = $showCache = $showParams = $showIndex = $showLocales = true;
        }

        if ($showParams) {
            $this->renderParamsSection($io, $em);
        }

        if ($showLocales) {
            $this->renderLocalesSection($io);
        }

        if ($showCarriers) {
            $this->renderCarriersSection($io, $class);
        }

        if ($showIndex) {
            $this->renderIndexSection($io, $class);
        }

        if ($showScan) {
            $this->renderLiveScanSection($io, $class, $em);
        }

        if ($showCache) {
            $this->renderCacheSection($io, $class);
        }

        return Command::SUCCESS;
    }

    private function renderParamsSection(SymfonyStyle $io, ?string $em): void
    {
        $io->section('Compiler-pass parameters');

        $ems        = $this->params->has('survos_babel.scan_entity_managers')
            ? (array) $this->params->get('survos_babel.scan_entity_managers')
            : [];
        $namespaces = $this->params->has('survos_babel.allowed_namespaces')
            ? (array) $this->params->get('survos_babel.allowed_namespaces')
            : [];
        $scanRoots  = $this->params->has('survos_babel.scan_roots')
            ? (array) $this->params->get('survos_babel.scan_roots')
            : [];

        $io->listing([
            'scan_entity_managers: ' . \json_encode($ems, JSON_UNESCAPED_SLASHES),
            'allowed_namespaces:   ' . \json_encode($namespaces, JSON_UNESCAPED_SLASHES),
            'scan_roots:           ' . \json_encode($scanRoots, JSON_UNESCAPED_SLASHES),
        ]);

        if ($em) {
            $io->writeln(\sprintf('<info>Using --em=%s to filter live scans below.</info>', $em));
        }
    }

    private function renderLocalesSection(SymfonyStyle $io): void
    {
        $io->section('Locales');

        $default    = $this->localeContext->getDefault();
        $enabled    = $this->localeContext->getEnabled();
        $paramLocs  = $this->params->has('framework.translator.enabled_locales')
            ? (array) $this->params->get('framework.translator.enabled_locales')
            : null;
        $kernelLoc  = $this->params->has('kernel.default_locale')
            ? (string) $this->params->get('kernel.default_locale')
            : null;

        $io->listing([
            'Babel default locale:   ' . $default,
            'Babel enabled locales:  ' . \json_encode($enabled, JSON_UNESCAPED_SLASHES),
            'framework.translator.enabled_locales: ' . \json_encode($paramLocs, JSON_UNESCAPED_SLASHES),
            'kernel.default_locale:  ' . \json_encode($kernelLoc, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function renderCarriersSection(SymfonyStyle $io, ?string $class): void
    {
        $io->section('Carriers (#[BabelStorage])');

        $found  = $this->carriers->listCarriers();
        $filter = static fn (string $fqcn): bool => !$class || \strcasecmp($fqcn, $class) === 0;

        $io->writeln('<comment>Code-mode carriers</comment>');
        foreach (\array_filter($found['code'] ?? [], $filter) as $fqcn) {
            $io->writeln(" - $fqcn");
        }

        $io->writeln('<comment>Property-mode carriers</comment>');
        foreach (\array_filter($found['property'] ?? [], $filter) as $fqcn) {
            $cfg         = $this->index->configFor($fqcn) ?? [];
            $source      = $cfg['sourceLocale'] ?? null;
            $targetsRaw  = $this->index->targetLocalesFor($fqcn);
            $enabledGlob = $this->localeContext->getEnabled() ?: [$this->localeContext->getDefault()];
            $targetsEff  = $targetsRaw === null ? $enabledGlob : $targetsRaw;

            $extra = [];
            if ($source) {
                $extra[] = 'src=' . $source;
            }
            if ($targetsRaw === []) {
                $extra[] = 'targets=[] (no translations)';
            } else {
                $extra[] = 'targets=' . \json_encode($targetsEff, JSON_UNESCAPED_SLASHES);
            }

            $suffix = $extra ? ' [' . \implode(', ', $extra) . ']' : '';
            $io->writeln(" - {$fqcn}{$suffix}");
        }
    }

    private function renderIndexSection(SymfonyStyle $io, ?string $class): void
    {
        $io->section('Compile-time translatable index (survos_babel.translatable_index)');

        $map = $this->index->all();
        if ($class) {
            $map = \array_intersect_key($map, [$class => true]);
        }

        if ($map === []) {
            $io->warning('No entries in the compile-time index. Are your entities tagged with #[BabelStorage(Property)] and scanned via survos_babel.scan_roots?');

            return;
        }

        foreach ($map as $fqcn => $cfg) {
            $fields        = \array_keys($cfg['fields'] ?? []);
            $localeAccessor = $cfg['localeAccessor'] ?? null;
            $sourceLocale   = $cfg['sourceLocale'] ?? null;
            $targets        = $cfg['targetLocales'] ?? null;

            $io->writeln(" <info>{$fqcn}</info>");
            $io->writeln('   Fields: ' . \json_encode($fields, JSON_UNESCAPED_SLASHES));
            $io->writeln('   sourceLocale: ' . \json_encode($sourceLocale, JSON_UNESCAPED_SLASHES));
            $io->writeln('   targetLocales: ' . \json_encode($targets, JSON_UNESCAPED_SLASHES));
            $io->writeln('   localeAccessor: ' . \json_encode($localeAccessor, JSON_UNESCAPED_SLASHES));
            $io->writeln('');
        }
    }

    private function renderLiveScanSection(SymfonyStyle $io, ?string $class, ?string $em): void
    {
        $io->section('Live scan (property-mode translatable fields via #[Translatable])');

        // NOTE: currently TranslatableScanner does not consume --em directly;
        // if you want, you can adapt it to use $em. For now we just call buildMap().
        $map = $this->scanner->buildMap();
        if ($class) {
            $map = \array_intersect_key($map, [$class => true]);
        }

        if (!$map) {
            $io->warning('No translatable fields found by live scanner. Are your entities tagged with #[BabelStorage(Property)] and properties with #[Translatable]?');

            return;
        }

        foreach ($map as $fqcn => $fields) {
            $io->writeln(" <info>$fqcn</info>");
            foreach ($fields as $f) {
                $io->writeln("   - $f");
            }
        }
    }

    private function renderCacheSection(SymfonyStyle $io, ?string $class): void
    {
        $io->section('Cached translatable map (cache.app via TranslatableMapProvider)');

        $cached = $this->provider->get();
        if ($class) {
            $cached = \array_intersect_key($cached, [$class => true]);
        }

        $totalClasses = \count($cached);
        $totalFields  = \array_sum(\array_map('count', $cached));

        $io->writeln(\sprintf('Classes: %d, Fields: %d', $totalClasses, $totalFields));

        foreach ($cached as $fqcn => $fields) {
            $io->writeln(" <info>$fqcn</info>");
            foreach ($fields as $f) {
                $io->writeln("   - $f");
            }
        }

        $io->writeln('');
        $io->note('Rebuild cache with: bin/console cache:warmup');
    }
}
