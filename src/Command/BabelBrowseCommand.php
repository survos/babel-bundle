<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Attribute\BabelTerm;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Show translated fields for an entity (string + term aware; DBAL fallback for translations)')]
final class BabelBrowseCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Entity FQCN (e.g. App\\Entity\\Product) or short name (e.g. Product)')] ?string $entity = null,
        #[Option('Display locale (defaults to current request/Context)', shortcut: 'l')] ?string $locale = null,
        #[Option('Max rows to show')] int $limit = 5,
        #[Option('Engine for str_tr lookup (default: babel)')] string $engine = 'babel',
    ): int {
        // Friendly guard when users type: babel:browse fr
        if ($entity && \preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $entity) && !$locale) {
            $io->note(sprintf('Did you mean: babel:browse <Entity> --locale=%s ?', $entity));
            return Command::INVALID;
        }

        if (!$entity) {
            $io->error('Missing entity argument (e.g., Product).');
            return Command::INVALID;
        }

        if ($locale) {
            $this->locale->set($locale);
        }
        $displayLocale = $this->locale->get();

        $fqcn = \class_exists($entity) ? $entity : 'App\\Entity\\' . $entity;
        if (!\class_exists($fqcn)) {
            $io->error(sprintf('Entity class not found: %s', $entity));
            return Command::INVALID;
        }

        $repo = $this->em->getRepository($fqcn);
        $items = $repo->createQueryBuilder('e')->setMaxResults($limit)->getQuery()->getResult();

        $io->title(sprintf('%s (locale=%s)', $fqcn, $displayLocale));

        // String-backed fields (compiler-built index; fallback to common names)
        $fields = $this->index->fieldsFor($fqcn);
        if ($fields === []) {
            $fields = ['title', 'name', 'label', 'content', 'description'];
        }

        // Term-backed fields discovered by reflection (BabelTerm attribute)
        $termFields = $this->resolveTermFields($fqcn);

        $rows = [];
        foreach ($items as $i => $obj) {
            $row = ['#' => (string)($obj->id ?? ($i + 1))];

            $srcLocale = $this->resolveSourceLocaleViaIndex($obj, $fqcn) ?: $this->locale->getDefault();

            // Translatable string fields
            foreach ($fields as $f) {
                if (!\property_exists($obj, $f)) {
                    continue;
                }

                $val = $obj->{$f};

                if (!\is_string($val) || $val === '') {
                    $row[$f] = $val;
                    continue;
                }

                $context = $this->resolveTranslatableContext($fqcn, $f);

                // Must match the ensureStrCode strategy used during inserts
                $strCode = hash('xxh3', $srcLocale . '|' . ($context ?? '') . '|' . $val);
                $translated = $this->fetchStrTrText($strCode, $displayLocale, $engine);

                $row[$f] = ($translated !== null && $translated !== '') ? $translated : $val;
            }

            // Term fields: show translated labels (via term.label_code -> str_tr.text)
            foreach ($termFields as $prop => $termMeta) {
                if (!\property_exists($obj, $prop)) {
                    continue;
                }

                $row[$prop] = $this->resolveTermLabels(
                    setCode: $termMeta['set'],
                    multiple: $termMeta['multiple'],
                    rawValue: $obj->{$prop},
                    displayLocale: $displayLocale,
                    engine: $engine
                );
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
     * @return array<string, array{set:string, multiple:bool}>
     */
    private function resolveTermFields(string $fqcn): array
    {
        $r = new \ReflectionClass($fqcn);
        $out = [];
        foreach ($r->getProperties() as $p) {
            $attrs = $p->getAttributes(BabelTerm::class);
            if (!$attrs) {
                continue;
            }
            /** @var BabelTerm $meta */
            $meta = $attrs[0]->newInstance();
            $out[$p->getName()] = ['set' => $meta->set, 'multiple' => $meta->multiple];
        }
        return $out;
    }

    private function resolveTranslatableContext(string $fqcn, string $prop): ?string
    {
        try {
            $r = new \ReflectionClass($fqcn);
            if (!$r->hasProperty($prop)) {
                return null;
            }
            $p = $r->getProperty($prop);
            $attrs = $p->getAttributes(Translatable::class);
            if (!$attrs) {
                return null;
            }
            /** @var Translatable $t */
            $t = $attrs[0]->newInstance();
            return $t->context;
        } catch (\Throwable) {
            return null;
        }
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

    private function fetchStrTrText(string $strCode, string $targetLocale, string $engine): ?string
    {
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :code AND %s = :loc AND %s = :eng LIMIT 1',
            BabelSchema::STR_TR_TEXT,
            BabelSchema::STR_TR_TABLE,
            BabelSchema::STR_TR_STR_CODE,
            BabelSchema::STR_TR_TARGET_LOCALE,
            BabelSchema::STR_TR_ENGINE
        );

        $val = $this->db->fetchOne($sql, ['code' => $strCode, 'loc' => $targetLocale, 'eng' => $engine]);

        return $val === false ? null : (string) $val;
    }

    private function resolveTermLabels(
        string $setCode,
        bool $multiple,
        mixed $rawValue,
        string $displayLocale,
        string $engine
    ): string {
        $codes = [];

        if ($multiple) {
            if (\is_array($rawValue)) {
                foreach ($rawValue as $v) {
                    $v = \is_string($v) ? trim($v) : '';
                    if ($v !== '') {
                        $codes[] = $v;
                    }
                }
            }
        } else {
            if (\is_string($rawValue) && trim($rawValue) !== '') {
                $codes[] = trim($rawValue);
            }
        }

        if ($codes === []) {
            return '';
        }

        $sql = sprintf(
            'SELECT t.%s AS term_code, t.%s AS label_code
             FROM %s t
             JOIN %s s ON s.%s = t.%s
             WHERE s.%s = :set AND t.%s IN (:codes)',
            BabelSchema::TERM_CODE,
            BabelSchema::TERM_LABEL_CODE,
            BabelSchema::TERM_TABLE,
            BabelSchema::TERM_SET_TABLE,
            BabelSchema::TERM_SET_ID,
            BabelSchema::TERM_TERM_SET_ID,
            BabelSchema::TERM_SET_CODE,
            BabelSchema::TERM_CODE
        );

        $rows = $this->db->executeQuery(
            $sql,
            ['set' => $setCode, 'codes' => $codes],
            ['codes' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $labelCodeByTerm = [];
        foreach ($rows as $r) {
            $labelCodeByTerm[(string) $r['term_code']] = $r['label_code'] ? (string) $r['label_code'] : null;
        }

        $labels = [];
        foreach ($codes as $c) {
            $labelCode = $labelCodeByTerm[$c] ?? null;
            if ($labelCode) {
                $t = $this->fetchStrTrText($labelCode, $displayLocale, $engine);
                $labels[] = ($t !== null && $t !== '') ? $t : $c;
            } else {
                $labels[] = $c;
            }
        }

        return implode(', ', $labels);
    }
}
