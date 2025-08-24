<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractBabelCommand extends Command
{
    public function __construct(
        private readonly LocaleContext $localeContext
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // shared across all Babel commands that extend this base
        $this->addOption(
            'src-locale',
            null,
            InputOption::VALUE_REQUIRED,
            'Override the *source* locale for this run (e.g., "en", "es-ES").'
        );
    }

    /** Call early in __invoke() (after autowiring) */
    protected function applyLocaleOverride(InputInterface $input): void
    {
        /** @var ?string $override */
        $override = $input->getOption('src-locale');
        if (\is_string($override) && $override !== '') {
            $this->localeContext->set($override);
        }
    }
}
