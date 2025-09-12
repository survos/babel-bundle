<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Runtime\BabelRuntime;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\HashUtil;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Show translated fields for an entity (property-mode uses postLoad hydration)')]
final class BabelBrowseCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle                                                                                $io,
        InputInterface                                                                              $input,
        #[Argument('Entity FQCN (e.g. App\\Entity\\Article) or short name (e.g. Article)')] ?string $entity = null,
        #[Option('Target locale (defaults to current request/Context)', shortcut: 'l')] ?string     $locale = null,
        #[Option('Max rows to show')] int                                                           $limit = 5,
    ): int {
        // Friendly guard when users type: babel:browse fr
        if ($entity && \preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $entity) && !$locale) {
            $io->note(sprintf('Did you mean: babel:browse <Entity> --locale=%s ?', $entity));
            return Command::INVALID;
        }

        if (!$entity) {
            $io->error('Missing entity argument (e.g., Article).');
            return Command::INVALID;
        }

        // Apply requested display locale so property hooks (if any) would select the right text
        if ($locale) {
            $this->locale->set($locale);
        }
        $displayLocale = $this->locale->get();

        $fqcn = \class_exists($entity) ? $entity : 'App\\Entity\\'.$entity;
        if (!\class_exists($fqcn)) {
            $io->error(sprintf('Entity class not found: %s', $entity));
            return Command::INVALID;
        }

        $repo = $this->em->getRepository($fqcn);
        $items = $repo->createQueryBuilder('e')->setMaxResults($limit)->getQuery()->getResult();

        $io->title(sprintf('%s (locale=%s)', $fqcn, $displayLocale));

        // Decide which fields to show: look up translatables via the index; fallback to common names.
        $fields = $this->index->fieldsFor($fqcn);
        if ($fields === []) {
            $fields = ['title','name','label','content','description'];
        }

        $rows = [];
        foreach ($items as $i => $obj) {
            $row = ['#' => (string)($obj->id ?? ($i+1))];

            // Try to learn the source locale from the entity using TranslatableIndex
            $srcLocale = $this->resolveSourceLocaleViaIndex($obj, $fqcn) ?: $this->locale->getDefault();

            foreach ($fields as $f) {
                if (!\property_exists($obj, $f)) {
                    continue;
                }
                $original = $obj->{$f}; // property hooks might already hydrate; we still compute from the backing value
                if (!\is_string($original) || $original === '') {
                    $row[$f] = $original;
                    continue;
                }

                // Compute the canonical STR key and fetch TR by (str_hash, locale)
                $strHash = HashUtil::calcSourceKey($original, $srcLocale);

                $translated = $this->fetchTranslation(BabelRuntime::TRANSLATION_TABLE, $strHash, $displayLocale);
                if ($translated !== null && $translated !== '') {
                    $row[$f] = $translated;
                } else {
                    // fall back to original (and make it obvious if debugging is on)
                    $row[$f] = $original;
                }
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

    private function resolveSourceLocaleViaIndex(object $entity, string $fqcn): ?string
    {
        $acc = $this->index->localeAccessorFor($fqcn);
        if (!$acc) {
            return null;
        }
        if ($acc['type'] === 'prop' && \property_exists($entity, $acc['name'])) {
            $v = $entity->{$acc['name']} ?? null;
            return \is_string($v) && $v !== '' ? $v : null;
        }
        if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
            $v = $entity->{$acc['name']}();
            return \is_string($v) && $v !== '' ? $v : null;
        }
        return null;
    }

    private function fetchTranslation(string $trTable, string $strHash, string $locale): ?string
    {
        // Query by the correct conflict key (str_hash, locale); ignore TR.hash formatting.
        $sql = sprintf('SELECT text FROM %s WHERE str_hash = :s AND locale = :l LIMIT 1', $trTable);
        $val = $this->db->fetchOne($sql, ['s' => $strHash, 'l' => $locale]);

        return $val === false ? null : (string)$val;
    }
}
