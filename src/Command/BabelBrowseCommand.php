<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Show translated fields for an entity (property-mode uses postLoad hydration)')]
final class BabelBrowseCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity FQCN (e.g. App\\Entity\\Article) or short name (e.g. Article)')] string $entity,
        #[Option('Target locale (defaults to current request/Context)')] ?string $locale = null,
        #[Option('Max rows to show')] int $limit = 5,
    ): int {
        // Resolve class from short name if needed
        if (!class_exists($entity)) {
            $candidate = 'App\\Entity\\' . ltrim($entity, '\\');
            if (class_exists($candidate)) {
                $entity = $candidate;
            } else {
                $io->error(sprintf('Entity class not found: %s', $entity));
                return Command::FAILURE;
            }
        }

        $fields = $this->index->fieldsFor($entity);
        if ($fields === []) {
            $io->warning(sprintf('No translatable fields in index for %s.', $entity));
            return Command::SUCCESS;
        }

        if ($locale) {
            try { $this->locale->set($locale); }
            catch (\Throwable $e) { $io->warning('Locale not set: '.$e->getMessage()); }
        }
        $target = $this->locale->get();

        $repo = $this->em->getRepository($entity);

        // Try to get a few rows (works for most apps). Adjust as needed.
        $items = $repo->findBy([], null, max(1, $limit));
        if (!$items) {
            $io->note('No rows found.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('%s (locale=%s)', $entity, $target));
        $headers = array_merge(['#'], $fields);
        $rows = [];

        $i = 1;
        foreach ($items as $obj) {
            // postLoad should have hydrated setResolvedTranslation(); using public property
            $line = [$i++];
            foreach ($fields as $f) {
                try {
                    // reading public property triggers hooks getter; hydrator already set resolved cache
                    $line[] = (string)($obj->$f ?? '');
                } catch (\Throwable $e) {
                    $this->logger?->debug('browse: read error', ['field'=>$f,'err'=>$e->getMessage()]);
                    $line[] = '(error)';
                }
            }
            $rows[] = $line;
        }

        $io->table($headers, $rows);
        return Command::SUCCESS;
    }
}
