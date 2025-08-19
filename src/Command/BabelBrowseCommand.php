<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Contract\TranslatableResolvedInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslationStore;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Display primary key and translatable public fields for an entity in the target language (or source if no locale is given)')]
final class BabelBrowseCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationStore $store,
        private readonly LocaleContext $contextLocale,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity short name or FQCN')] string $entityName,
        #[Argument('Target locale (omit to show source text)')] ?string $locale = null,
        #[Option('Output format: text or json')] string $format = 'text',
        #[Option('Maximum rows to display (0 = all)')] int $limit = 100,
        #[Option('Starting offset (0-based)')] int $offset = 0,
    ): int {
        $format = strtolower($format);
        if (!\in_array($format, ['text','json'], true)) {
            $io->error('Invalid --format. Use text or json.');
            return 2;
        }

        $class = $this->resolveClass($entityName);
        if (!$class || !class_exists($class)) {
            $io->error(sprintf('Entity class not found: %s', $entityName));
            return 1;
        }

        // Pull translatable fields (precomputed index from TranslationStore)
        $cfg = $this->store->getEntityConfig($class) ?? ['fields' => []];
        $fieldMap = $cfg['fields'] ?? []; // [field => ['context'=>...?]]

        if (!$fieldMap) {
            $io->warning(sprintf('No #[Translatable] public fields were discovered on %s.', $class));
        }

        // Set desired locale for postLoad (null=>keep source)
        $this->contextLocale->set($locale ? $this->normalizeLocale($locale) : null);

        $repo = $this->em->getRepository($class);
        $qb = $repo->createQueryBuilder('e');
        if ($offset > 0) $qb->setFirstResult($offset);
        if ($limit > 0)  $qb->setMaxResults($limit);

        // Hydrate (postLoad will fill resolved translations if locale set)
        $rows = $qb->getQuery()->getResult();
        $meta = $this->em->getClassMetadata($class);

        $out = [];
        foreach ($rows as $entity) {
            $idVals = $meta->getIdentifierValues($entity);
            $id = (count($idVals) === 1) ? (string)array_values($idVals)[0] : implode(':', array_map('strval', $idVals));

            $line = ['id' => $id];

            foreach (array_keys($fieldMap) as $propName) {
                if (!property_exists($entity, $propName)) {
                    continue;
                }
                $value = null;
                if ($entity instanceof TranslatableResolvedInterface) {
                    $value = $entity->getResolvedTranslation($propName) ?? $entity->$propName;
//                    $propName === 'description' && dd($propName, $value);
                } else {
//                    dd($entity::class . " is not TranslatableResolvedInterface ");
                    $value = $entity->$propName;
                }
                if ($value && ($format === 'text')) {
                    $value = mb_substr($value, 0, 40) . '...';
                }
                $line[$propName] = $value;
            }

            $out[] = $line;
        }

        if ($format === 'json') {
            $io->writeln(json_encode(
                [
                    'entity' => $class,
                    'count'  => count($out),
                    'offset' => $offset,
                    'limit'  => $limit,
                    'data'   => $out,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $io->title(sprintf('Browsing %s (%d rows%s)', $class, count($out), $limit > 0 ? '' : ', unbounded'));
            if ($out) {
                $headers = array_keys($out[0]);
                $rowsOut = array_map(fn(array $r) => array_values($r), $out);
                $io->table($headers, $rowsOut);
            } else {
                $io->text('No rows.');
            }
        }

        return 0;
    }

    /** Accepts "App:Entity", "Entity", or FQCN. */
    private function resolveClass(string $name): ?string
    {
        if (str_contains($name, '\\') && class_exists($name)) {
            return $name;
        }

        if (str_contains($name, ':')) {
            [$ns, $short] = explode(':', $name, 2);
            $candidates = [
                $ns.'\\Entity\\'.$short,
                $ns.'\\'.$short,
            ];
            foreach ($candidates as $c) {
                if (class_exists($c)) return $c;
            }
        }

        $candidates = [
            'App\\Entity\\'.$name,
            'Survos\\PixieBundle\\Entity\\'.$name,
        ];
        foreach ($candidates as $c) {
            if (class_exists($c)) return $c;
        }
        return null;
    }

    private function normalizeLocale(string $s): string
    {
        $s = str_replace('_', '-', $s);
        if (preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $s, $m)) {
            $lang = strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $s;
    }
}
