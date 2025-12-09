<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\CarrierRegistry;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:carriers', 'List discovered carriers with #[BabelStorage] grouped by storage mode (with locales).')]
final class CarriersListCommand
{
    public function __construct(
        private readonly CarrierRegistry $registry,
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $localeContext,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $all = $this->registry->listCarriers();

        // Global locales
        $io->section('Locales');
        $default = $this->localeContext->getDefault();
        $enabled = $this->localeContext->getEnabled();
        $io->listing([
            'default:  ' . $default,
            'enabled:  ' . \json_encode($enabled, JSON_UNESCAPED_SLASHES),
        ]);

        // Code-mode carriers
        $io->section('Code-mode carriers');
        foreach ($all['code'] ?? [] as $fqcn) {
            $io->writeln(" - $fqcn");
        }

        // Property-mode carriers with locale info
        $io->section('Property-mode carriers');
        $globalEnabled = $enabled ?: [$default];

        foreach ($all['property'] ?? [] as $fqcn) {
            $cfg        = $this->index->configFor($fqcn) ?? [];
            $source     = $cfg['sourceLocale'] ?? null;
            $targetsRaw = $this->index->targetLocalesFor($fqcn);
            $targetsEff = $targetsRaw === null ? $globalEnabled : $targetsRaw;

            $pieces = [];
            if ($source) {
                $pieces[] = 'src=' . $source;
            }
            if ($targetsRaw === []) {
                $pieces[] = 'targets=[] (no translations)';
            } else {
                $pieces[] = 'targets=' . \json_encode($targetsEff, JSON_UNESCAPED_SLASHES);
            }

            $suffix = $pieces ? ' [' . \implode(', ', $pieces) . ']' : '';

            // If there's no entry in the index at all, flag it.
            if ($cfg === []) {
                $suffix .= ' (no compile-time index entry)';
            }

            $io->writeln(" - {$fqcn}{$suffix}");
        }

        return Command::SUCCESS;
    }
}
