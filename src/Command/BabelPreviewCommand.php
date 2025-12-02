<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('babel:preview', 'Preview Doctrine-hydrated translatable fields under a given locale')]
final class BabelPreviewCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocaleContext $localeContext,
        private readonly TranslatableIndex $index,
        #[Autowire(param: 'survos_babel.scan_namespaces')]
        private readonly array $scanNamespaces = [],
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity FQCN (e.g. App\\Entity\\Article) or short name (e.g. Article)')]
        ?string $entity = null,
        #[Option('Locale to preview (defaults to LocaleContext current locale)', 'l')]
        ?string $locale = null,
        #[Option('Max rows to show')]
        int $limit = 5,
    ): int {
        if (!$entity) {
            $io->error('Missing entity argument (e.g., Article or App\\Entity\\Article).');
            return Command::INVALID;
        }

        // Friendly guard when users type: babel:preview fr
        if ($entity && \preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $entity) && !$locale) {
            $io->note(sprintf('Did you mean: babel:preview <Entity> --locale=%s ?', $entity));
            return Command::INVALID;
        }

        $fqcn = $this->resolveEntityClass($entity);
        if (!$fqcn) {
            $io->error(sprintf(
                'Entity class not found for "%s". Tried as FQCN and within: %s',
                $entity,
                implode(', ', $this->scanNamespaces ?: ['(none)'])
            ));
            return Command::INVALID;
        }

        // Decide which locale to preview and push it into LocaleContext
        $effectiveLocale = $locale ?: $this->localeContext->get();
        $this->localeContext->set($effectiveLocale);

        $io->title(sprintf('%s — preview (locale=%s)', $fqcn, $effectiveLocale));

        try {
            $repo = $this->em->getRepository($fqcn);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to get repository for "%s": %s', $fqcn, $e->getMessage()));
            return Command::FAILURE;
        }

        $items = $repo->createQueryBuilder('e')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!$items) {
            $io->writeln('(no rows)');
            return Command::SUCCESS;
        }

        // Decide which fields to show: translatable fields if known, fallback to common ones.
        $fields = $this->index->fieldsFor($fqcn);
        if ($fields === []) {
            $fields = ['title', 'name', 'label', 'content', 'description'];
        }

        $rows = [];
        foreach ($items as $i => $obj) {
            $row = ['#' => (string)($obj->id ?? ($i + 1))];

            foreach ($fields as $f) {
                if (!\property_exists($obj, $f)) {
                    continue;
                }

                // This access is the whole point: Doctrine has loaded the entity,
                // BabelPostLoadHydrator / property hooks should already have swapped
                // in the localized value for the current LocaleContext.
                $row[$f] = $obj->{$f};
            }

            $rows[] = $row;
        }

        if ($rows) {
            $io->table(array_keys($rows[0]), $rows);
        } else {
            $io->writeln('(no rows)');
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve an entity class from either:
     *  - a fully-qualified class name, or
     *  - a short name using survos_babel.scan_namespaces (e.g. "Article").
     */
    private function resolveEntityClass(string $entity): ?string
    {
        // 1) Exact FQCN
        if (\class_exists($entity)) {
            return $entity;
        }

        // 2) Try each scan namespace with a short name
        $short = ltrim($entity, '\\');
        foreach ($this->scanNamespaces as $ns) {
            $ns = rtrim((string) $ns, '\\');
            if ($ns === '') {
                continue;
            }
            $candidate = $ns.'\\'.$short;
            if (\class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
