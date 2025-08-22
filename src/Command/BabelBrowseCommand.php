<?php
// src/Command/BabelBrowseCommand.php

declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Browse translations for an entity')]
final class BabelBrowseCommand extends Command
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly LocaleContext $contextLocale,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', mode: Argument::OPTIONAL, description: 'Entity class to browse')
            ->addArgument('locale', mode: Argument::OPTIONAL, description: 'Target locale (defaults to context locale)')
            ->addOption('limit', mode: Option::VALUE_OPTIONAL, description: 'Limit results', default: 20);
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $class  = $input->getArgument('class');
        $locale = $input->getArgument('locale') ?? $this->contextLocale->get();
        $limit  = (int) $input->getOption('limit');

        if (!$class) {
            $io->warning('No class specified.');
            return Command::INVALID;
        }

        $config = $this->store->getEntityConfig($class);
        if (!$config) {
            $io->warning("No translatable config found for $class");
            return Command::INVALID;
        }

        $missing = $this->store->iterateMissing($locale, null, $limit);

        $rows = [];
        foreach ($missing as $str) {
            $rows[] = [
                'hash'    => $str->hash,
                'orig'    => mb_substr($str->original, 0, 50),
                'locale'  => $locale,
                'context' => $str->context,
            ];
        }

        if (!$rows) {
            $io->success("No missing translations for $class in locale $locale.");
            return Command::SUCCESS;
        }

        $io->title("Missing translations for $class [$locale]");
        $io->table(['Hash', 'Original', 'Locale', 'Context'], $rows);

        return Command::SUCCESS;
    }
}
