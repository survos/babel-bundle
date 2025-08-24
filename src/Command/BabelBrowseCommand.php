<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\Engine\EngineResolver;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Display PK + translatable fields for an entity using cached engine + field index')]
final class BabelBrowseCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EngineResolver         $engineResolver,
        private readonly TranslatableIndex      $translatableIndex,     // cached field map
        private readonly LocaleContext          $localeContext, // set desired locale
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity FQCN or short name (e.g. App\\Entity\\Glam or Glam)')] string $entity,
        #[Option('Target locale (leave empty to use current)')] ?string $locale = null,
        #[Option('Output format: text or json')] string $format = 'text',
        #[Option('Limit rows (0=all)')] int $limit = 50,
        #[Option('Offset (0-based)')] int $offset = 0,
    ): int {
        $class = $this->resolveClass($entity);
        if (!$class || !class_exists($class)) {
            $io->error("Entity class not found: $entity");
            return 1;
        }

        // Set target locale
        if ($locale) {
            $this->localeContext->set($this->normalizeLocale($locale));
        }

        // Resolve engine (just to show which storage is bound for this entity)
        $engineName = '(router)';
        try {
            $engine = $this->engineResolver->resolveEngine($class);
            $engineName = get_debug_type($engine);
        } catch (\Throwable $e) {
            $this->logger->notice('EngineResolver failed for browse', ['entity' => $class, 'err' => $e->getMessage()]);
        }

        // Resolve translatable fields from cached index (fall back to engine method names if any)
        $fields = $this->resolveTranslatableFields($class, $engine ?? null);
        if (!$fields) {
            $io->warning("No translatable fields discovered for $class");
        }

        // Fetch entities
        $repo = $this->em->getRepository($class);
        $qb = $repo->createQueryBuilder('e');
        if ($offset > 0) $qb->setFirstResult($offset);
        if ($limit > 0)  $qb->setMaxResults($limit);
        $rows = $qb->getQuery()->getResult();

        // PK names
        $meta = $this->em->getClassMetadata($class);
        $idNames = $meta->getIdentifierFieldNames();

        // Build output
        $out = [];
        foreach ($rows as $row) {
            dd($row->description);
            // PK string
            $ids = $meta->getIdentifierValues($row);
            $id = (count($ids) === 1) ? (string)array_values($ids)[0] : implode(':', array_map('strval', array_values($ids)));

            $line = ['id' => $id];
            foreach ($fields as $f) {
                if (!\property_exists($row, $f)) { continue; }
                $line[$f] = $row->$f; // hooks resolve based on LocaleContext
            }
            $out[] = $line;
        }

        $io->title(sprintf('Browse %s (engine: %s, locale: %s)', $class, $engineName, $locale ?: $this->localeContext->get()));

        if ($format === 'json') {
            $io->writeln(json_encode(
                ['entity' => $class, 'count' => count($out), 'offset' => $offset, 'limit' => $limit, 'data' => $out],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            if ($out) {
                $headers = array_keys($out[0]);
                $io->table($headers, array_map(fn($r) => array_values($r), $out));
            } else {
                $io->text('No rows.');
            }
        }

        return 0;
    }

    private function resolveClass(string $name): ?string
    {
        // FQCN
        if (\str_contains($name, '\\') && \class_exists($name)) return $name;

        // "App:Entity" pattern
        if (\str_contains($name, ':')) {
            [$ns, $short] = explode(':', $name, 2);
            foreach ([$ns.'\\Entity\\'.$short, $ns.'\\'.$short] as $c) {
                if (\class_exists($c)) return $c;
            }
        }

        // common guesses
        foreach (['App\\Entity\\'.$name] as $c) {
            if (\class_exists($c)) return $c;
        }

        return null;
    }

    private function normalizeLocale(string $s): string
    {
        $s = \str_replace('_', '-', \trim($s));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $s, $m)) {
            $lang = \strtolower($m[1]); $reg = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $s;
    }

    /**
     * Get translatable field names from the cached index (preferred),
     * or from the engine if it exposes helper methods.
     */
    private function resolveTranslatableFields(string $class, ?object $engine): array
    {
        $fields = $this->translatableIndex->fieldsFor($class);
        return $fields;
    }
}
