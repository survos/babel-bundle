<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Service\Engine\EngineResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:populate', 'Create missing StrTranslation rows for the target locale(s)')]
final class PopulateMissingCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly EngineResolver $engineResolver, // compiled router-backed
        private readonly string $defaultLocale = 'en',
        private readonly array $enabledLocales = [],     // kernel.enabled_locales
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Filter by source (srcLocale) when available')] ?string $source = null,
        #[Option('Batch size for flush')] int $batchSize = 500,
        #[Option('Limit rows per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Entity FQCN to narrow the storage router (optional)')] ?string $entity = null,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
    ): int {
        // Resolve target locales
        $targets = [];
        if ($localesArg && \trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
        } elseif ($all) {
            $targets = $this->enabledLocales ?: [$this->defaultLocale];
        } else {
            $io->warning('Specify locales (e.g. "es,fr") or pass --all to use framework.enabled_locales.');
            return 1;
        }

        if (!$this->enabledLocales || (\count($this->enabledLocales) === 1 && $this->enabledLocales[0] === $this->defaultLocale)) {
            $io->warning("Meaningful 'framework.enabled_locales' is not configured. Add e.g. enabled_locales: ['en','es','de','fr']");
        }

        $io->title(sprintf('Populate missing translations (%s)', $entity ? $entity : 'all storages'));

        $trRepo  = $this->em->getRepository(StrTranslation::class);
        $strRepo = $this->em->getRepository(Str::class);

        $grandCreated = 0;
        foreach ($targets as $locale) {
            $io->section(sprintf('Locale: %s', $locale));

            // Try router-backed engine first
            $iterable = null;
            try {
                $iterable = $this->engineResolver->iterateMissing($entity, $locale, $source, $limit);
                $io->text('Backend: <info>compiled router</info>');
            } catch (\Throwable $e) {
                $this->logger->notice('EngineResolver failed; falling back to repositories', ['error' => $e->getMessage()]);
                $io->text('Backend: <comment>repository fallback</comment>');
            }

            $seen = 0;
            $created = 0;

            if ($iterable) {
                foreach ($iterable as $row) {
                    $seen++;
                    [$hash, $orig, $src, $ctx] = $this->extractRow($row);

                    if (!$hash) {
                        $this->logger->debug('Skip row without hash', ['row' => get_debug_type($row)]);
                        continue;
                    }

                    $exists = (bool) $trRepo->findOneBy(['hash' => $hash, 'locale' => $locale]);
                    if ($exists) {
                        continue;
                    }

                    if (!$dryRun) {
                        $tr = new StrTranslation($hash, $locale, '');
                        if (\property_exists($tr, 'status')) {
                            $tr->status = 'untranslated';
                        }
                        if (\property_exists($tr, 'updatedAt')) {
                            $tr->updatedAt = new \DateTimeImmutable();
                        }
                        $this->em->persist($tr);
                    }
                    $created++;

                    if (!$dryRun && ($created % $batchSize) === 0) {
                        $this->em->flush();
                        $this->em->clear(StrTranslation::class);
                    }

                    if ($limit > 0 && $seen >= $limit) {
                        break;
                    }
                }
            } else {
                // Fallback: simple repo scan
                $qb = $strRepo->createQueryBuilder('s');
                if ($source && \property_exists(Str::class, 'srcLocale')) {
                    $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
                }
                if ($limit > 0) $qb->setMaxResults($limit);

                foreach ($qb->getQuery()->toIterable() as $s) {
                    $seen++;
                    [$hash] = $this->extractRow($s);
                    if (!$hash) continue;

                    $exists = (bool) $trRepo->findOneBy(['hash' => $hash, 'locale' => $locale]);
                    if ($exists) continue;

                    if (!$dryRun) {
                        $tr = new StrTranslation($hash, $locale, '');
                        if (\property_exists($tr, 'status')) {
                            $tr->status = 'untranslated';
                        }
                        if (\property_exists($tr, 'updatedAt')) {
                            $tr->updatedAt = new \DateTimeImmutable();
                        }
                        $this->em->persist($tr);
                    }
                    $created++;

                    if (!$dryRun && ($created % $batchSize) === 0) {
                        $this->em->flush();
                        $this->em->clear(StrTranslation::class);
                    }
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }

            $grandCreated += $created;
            $io->success(sprintf('Locale %s: created %d missing StrTranslation rows', $locale, $created));
        }

        $io->success(sprintf('Total created: %d', $grandCreated));
        if ($dryRun) $io->note('Dry-run: no changes were written.');

        return 0;
    }

    /**
     * Try to extract common fields from engine rows or Str entities.
     * Returns [hash, original, srcLocale, context]
     */
    private function extractRow(object $row): array
    {
        $hash = null; $orig = null; $src = null; $ctx = null;

        foreach (['getHash','hash'] as $m) if ($hash === null && \method_exists($row, $m)) { $v = $row->$m(); $hash = \is_string($v) ? $v : $hash; }
        if ($hash === null && \property_exists($row, 'hash')) $hash = \is_string($row->hash) ? $row->hash : $hash;

        foreach (['getOriginal','original'] as $m) if ($orig === null && \method_exists($row, $m)) { $v = $row->$m(); $orig = \is_string($v) ? $v : $orig; }
        if ($orig === null && \property_exists($row, 'original')) $orig = \is_string($row->original) ? $row->original : $orig;

        foreach (['getSrcLocale','srcLocale'] as $m) if ($src === null && \method_exists($row, $m)) { $v = $row->$m(); $src = \is_string($v) ? $v : $src; }
        if ($src === null && \property_exists($row, 'srcLocale')) $src = \is_string($row->srcLocale) ? $row->srcLocale : $src;

        foreach (['getContext','context'] as $m) if ($ctx === null && \method_exists($row, $m)) { $v = $row->$m(); $ctx = \is_string($v) ? $v : $ctx; }
        if ($ctx === null && \property_exists($row, 'context')) $ctx = \is_string($row->context) ? $row->context : $ctx;

        return [$hash, $orig, $src, $ctx];
    }
}
