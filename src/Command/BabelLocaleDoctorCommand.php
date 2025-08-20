<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:locale:doctor', 'Inspect the current/default locales and (optionally) switch locale temporarily')]
final class BabelLocaleDoctorCommand
{
    public function __construct(
        private readonly LocaleContext $context,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('set', 'Switch to this locale for the duration of the command (null => default)')]
        ?string $set = null,
        #[Option('list', 'List enabled locales from framework.enabled_locales')]
        bool $list = false,
    ): int {
        if ($list) {
            $enabled = $this->context->getEnabled();
            $io->section('Enabled Locales');
            $io->writeln($enabled ? implode(', ', $enabled) : '(none configured)');
            $io->newLine();
        }

        $io->section('Before');
        $io->definitionList(
            ['Default' => $this->context->getDefault()],
            ['Current' => $this->context->get()],
        );

        // Allow null => reset to default
        $target = $set === 'null' ? null : $set;

        if ($set !== null) {
            $io->section('Switching');
            $io->writeln(sprintf('Setting locale to %s', $target ?? '(default)'));
            $this->context->set($target);
        }

        $io->section('After');
        $io->definitionList(
            ['Default' => $this->context->getDefault()],
            ['Current' => $this->context->get()],
        );

        $io->success('LocaleContext OK');
        return 0;
    }
}
