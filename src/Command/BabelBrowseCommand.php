<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;

#[AsCommand('babel:browse', 'Show translated fields for an entity (property-mode uses postLoad hydration)')]
final class BabelBrowseCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocaleContext $locale,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Entity FQCN (e.g. App\\Entity\\Article) or short name (e.g. Article)')] ?string $entity = null,
        #[Option('Target locale (defaults to current request/Context)', name: 'locale', shortcut: 'L')] ?string $localeOpt = null,
        #[Option('Max rows to show')] int $limit = 5,
    ): int {
        // Friendly guard when users type: babel:browse fr
        if ($entity && \preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $entity) && !$localeOpt) {
            $io->note(sprintf('Did you mean: babel:browse <Entity> --locale=%s ?', $entity));
            return 1;
        }

        if (!$entity) {
            $io->error('Missing entity argument (e.g. Article).'); return 1;
        }

        // Apply requested display locale so property hooks select the right text
        if ($localeOpt) {
            $this->locale->set($localeOpt);
        }

        $fqcn = \class_exists($entity) ? $entity : 'App\\Entity\\'.$entity;
        if (!\class_exists($fqcn)) {
            $io->error(sprintf('Entity class not found: %s', $entity));
            return 1;
        }

        $repo = $this->em->getRepository($fqcn);
        $items = $repo->createQueryBuilder('e')->setMaxResults($limit)->getQuery()->getResult();

        $io->title(sprintf('%s (locale=%s)', $fqcn, $this->locale->get()));

        // naive field pick: show any public translatable hooks if present
        $rows = [];
        foreach ($items as $i => $obj) {
            $row = ['#' => (string)($obj->id ?? ($i+1))];
            foreach (['title','name','label','content','description'] as $f) {
                if (\property_exists($obj, $f)) {
                    $row[$f] = $obj->{$f};
                }
            }
            $rows[] = $row;
        }
        if ($rows) {
            $io->table(array_keys($rows[0]), $rows);
        } else {
            $io->writeln('(no rows)');
        }
        return 0;
    }
}
