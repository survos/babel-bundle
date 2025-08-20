<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;

final class TranslationStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        /** @var array<class-string,array{fields:array<string,array{context:?string}>,localeProp:?string,hasTCodes:bool}> */
        public array $translatableIndex = [],   // injected by compiler pass (attributes scan)
        /** @var string[] */
        public readonly array $enabledLocales = [], // typically injected from kernel.enabled_locales (optional)
    ) {}

    public function setTranslatableIndex(array $index): void { $this->translatableIndex = $index; }

    /** guard against duplicate StrTranslation creation within same request */
    private array $trGuard = []; // [hash][locale] => object(StrTranslation)

    private function resolveClasses(): array
    {
        $strClass = '\\App\\Entity\\Str';
        $trClass  = '\\App\\Entity\\StrTranslation';
        if (!class_exists($strClass) || !class_exists($trClass)) {
            // fallback to Pixie’s
            $strClass = '\\Survos\\PixieBundle\\Entity\\Str';
            $trClass  = '\\Survos\\PixieBundle\\Entity\\StrTranslation';
        }
        return [$strClass, $trClass];
    }

    // ---------- READ HELPERS ------------------------------------------------

    public function iterateMissing(string $targetLocale, ?string $source = null, int $limit = 0): iterable
    {
        [$strClass, $trClass] = $this->resolveClasses();

        $qb = $this->em->getRepository($strClass)->createQueryBuilder('s');
        $qb->leftJoin($trClass, 't', Join::WITH, 't.hash = s.hash AND t.locale = :target')
            ->andWhere('t.hash IS NULL')
            ->setParameter('target', $targetLocale);

        if (property_exists($strClass, 'srcLocale') && $source) {
            $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    public function countMissing(string $targetLocale, ?string $source = null): int
    {
        [$strClass, $trClass] = $this->resolveClasses();

        $qb = $this->em->getRepository($strClass)->createQueryBuilder('s')
            ->select('COUNT(s.hash)')
            ->leftJoin($trClass, 't', Join::WITH, 't.hash = s.hash AND t.locale = :target')
            ->andWhere('t.hash IS NULL')
            ->setParameter('target', $targetLocale);

        if (property_exists($strClass, 'srcLocale') && $source) {
            $qb->andWhere('s.srcLocale = :src')->setParameter('src', $source);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getStatusSummary(array $locales): array
    {
        [, $trClass] = $this->resolveClasses();
        $repo = $this->em->getRepository($trClass);

        $hasStatus = property_exists($trClass, 'status');

        $summary = [];
        foreach ($locales as $loc) {
            if ($hasStatus) {
                $total = (int) $repo->createQueryBuilder('t')
                    ->select('COUNT(t.hash)')
                    ->andWhere('t.locale = :locale')
                    ->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();

                $untranslated = (int) $repo->createQueryBuilder('t2')
                    ->select('COUNT(t2.hash)')
                    ->andWhere('t2.locale = :locale')
                    ->andWhere('t2.status = :u')
                    ->getQuery()->setParameter('locale', $loc)->setParameter('u', 'untranslated')->getSingleScalarResult();

                $translated = max(0, $total - $untranslated);
            } else {
                $total = (int) $repo->createQueryBuilder('t')
                    ->select('COUNT(t.hash)')
                    ->andWhere('t.locale = :locale')
                    ->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();

                $untranslated = (int) $repo->createQueryBuilder('t')
                    ->select('COUNT(t.hash)')
                    ->andWhere('t.locale = :locale')
                    ->andWhere("COALESCE(t.text, '') = ''")
                    ->getQuery()->setParameter('locale', $loc)->getSingleScalarResult();

                $translated = max(0, $total - $untranslated);
            }

            $summary[$loc] = [
                'translated'   => $translated,
                'untranslated' => $untranslated,
                'total'        => $total,
            ];
        }

        return $summary;
    }

    public function hash(string $text, string $srcLocale, ?string $context = null): string
    {
        return hash('xxh3', $srcLocale . "\0" . $context . "\0" . $text);
    }

    public function get(string $hash, string $locale): ?string
    {
        [, $trClass] = $this->resolveClasses();
        $tr = $this->em->find($trClass, ['hash' => $hash, 'locale' => $locale]);
        return $tr?->text;
    }

    // ---------- LEGACY ORM UPSERT (kept for non-listener code) --------------

    public function upsert(
        string $hash,
        string $original,
        string $srcLocale,
        ?string $context,
        string $locale,
        string $text
    ): object {
        [$strClass, $trClass] = $this->resolveClasses();

        // Ensure Str
        $str = $this->em->find($strClass, $hash);
        if (!$str) {
            $str = new $strClass($hash, $original, $srcLocale, $context);
            $this->em->persist($str);
        }
        $str->updatedAt = new \DateTimeImmutable();

        // Ensure source translation
        $srcLocale = $this->normalizeLocale($srcLocale);
        $srcTr = $this->guardedTr($trClass, $hash, $srcLocale);
        if (!$srcTr) {
            $srcTr = new $trClass($hash, $srcLocale, $original);
            $this->em->persist($srcTr);
            $this->rememberTr($srcTr);
        }
        $srcTr->text      = $original;
        $srcTr->updatedAt = new \DateTimeImmutable();

        // Placeholders for all enabled locales
        foreach ($this->enabledLocales as $loc) {
            $loc = $this->normalizeLocale($loc);
            if ($loc === $srcLocale) {
                continue;
            }
            $tr = $this->guardedTr($trClass, $hash, $loc);
            if (!$tr) {
                $tr = new $trClass($hash, $loc, '');
                if (property_exists($tr, 'status')) {
                    $tr->status = 'untranslated';
                }
                $tr->updatedAt = new \DateTimeImmutable();
                $this->em->persist($tr);
                $this->rememberTr($tr);
            }
        }

        return $srcTr;
    }

    public function getEntityConfig(object|string $entity): ?array
    {
        $class = \is_object($entity) ? $entity::class : $entity;
        return $this->translatableIndex[$class] ?? null;
    }

    public function flush(): void { $this->em->flush(); }

    public function clearTranslationsOnly(): void
    {
        [, $trClass] = $this->resolveClasses();
        $this->em->clear($trClass);
        $this->trGuard = [];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $locale;
    }

    private function guardedTr(string $trClass, string $hash, string $locale): ?object
    {
        if (isset($this->trGuard[$hash][$locale])) {
            return $this->trGuard[$hash][$locale];
        }
        $found = $this->em->find($trClass, ['hash' => $hash, 'locale' => $locale]);
        if ($found) {
            $this->rememberTr($found);
        }
        return $found;
    }

    private function rememberTr(object $tr): void
    {
        $this->trGuard[$tr->hash][$tr->locale] = $tr;
    }

    // ---------- RAW UPSERT (DBAL) — identity-map safe -----------------------

    /**
     * Platform-aware raw upsert for Str and StrTranslation rows.
     * Writes immediately via DBAL; safe to call from Doctrine listeners.
     */
    public function upsertRaw(
        string $hash,
        string $original,
        string $srcLocale,
        ?string $context,
        string $localeForText,
        string $text
    ): void {
        [$strClass, $trClass] = $this->resolveClasses();
        $conn     = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();

        $strMeta = $this->em->getClassMetadata($strClass);
        $trMeta  = $this->em->getClassMetadata($trClass);

        $tStr = $strMeta->getTableName();
        $tTr  = $trMeta->getTableName();

        $col = function($meta, string $field): string {
            $m = $meta->getFieldMapping($field);
            return $m['columnName'] ?? $field;
        };

        // columns for Str
        $c_hash      = $col($strMeta, 'hash');
        $c_original  = $col($strMeta, 'original');
        $c_srcLocale = $col($strMeta, 'srcLocale');
        $c_context   = $col($strMeta, 'context');
        $c_createdAt = $col($strMeta, 'createdAt');
        $c_updatedAt = $col($strMeta, 'updatedAt');

        // columns for StrTranslation
        $ctr_hash      = $col($trMeta, 'hash');
        $ctr_locale    = $col($trMeta, 'locale');
        $ctr_text      = $col($trMeta, 'text');
        $ctr_createdAt = $col($trMeta, 'createdAt');
        $ctr_updatedAt = $col($trMeta, 'updatedAt');

        $ctr_status = $trMeta->hasField('status') ? $col($trMeta, 'status') : null;

        $now = new \DateTimeImmutable();

        // STR
        $this->upsertRow(
            platform:   $platform,
            table:      $tStr,
            pkCols:     [$c_hash],
            insertCols: [$c_hash, $c_original, $c_srcLocale, $c_context, $c_createdAt, $c_updatedAt],
            insertVals: [$hash,   $original,   $srcLocale,   $context,   $now,         $now],
            updateCols: [$c_original, $c_srcLocale, $c_context, $c_updatedAt],
            updateVals: [$original,   $srcLocale,   $context,   $now],
        );

        // SOURCE TRANSLATION (hash, srcLocale) with original text
        $this->upsertRow(
            platform:   $platform,
            table:      $tTr,
            pkCols:     [$ctr_hash, $ctr_locale],
            insertCols: [$ctr_hash, $ctr_locale, $ctr_text, $ctr_createdAt, $ctr_updatedAt] + [],
            insertVals: [$hash,     $srcLocale,  $original,  $now,           $now],
            updateCols: [$ctr_text, $ctr_updatedAt],
            updateVals: [$original, $now],
        );

        // TARGET TEXT if different locale is provided
        if ($localeForText !== $srcLocale) {
            $this->upsertRow(
                platform:   $platform,
                table:      $tTr,
                pkCols:     [$ctr_hash, $ctr_locale],
                insertCols: [$ctr_hash, $ctr_locale, $ctr_text, $ctr_createdAt, $ctr_updatedAt] + [],
                insertVals: [$hash,     $localeForText, $text,  $now,           $now],
                updateCols: [$ctr_text, $ctr_updatedAt],
                updateVals: [$text,     $now],
            );
        }

        // PLACEHOLDERS for all enabled locales
        foreach ($this->enabledLocales as $loc) {
            if ($loc === $srcLocale) {
                continue;
            }
            $insertCols = [$ctr_hash, $ctr_locale, $ctr_text, $ctr_createdAt, $ctr_updatedAt];
            $insertVals = [$hash,     $loc,        '',        $now,           $now];
            $updateCols = [$ctr_updatedAt];
            $updateVals = [$now];

            if ($ctr_status) {
                $insertCols[] = $ctr_status;
                $insertVals[] = 'untranslated';
            }

            $this->upsertRow(
                platform:   $platform,
                table:      $tTr,
                pkCols:     [$ctr_hash, $ctr_locale],
                insertCols: $insertCols,
                insertVals: $insertVals,
                updateCols: $updateCols,
                updateVals: $updateVals,
            );
        }
    }

    /**
     * Generic platform-aware UPSERT.
     *
     * @param list<string> $pkCols
     * @param list<string> $insertCols
     * @param list<mixed>  $insertVals
     * @param list<string> $updateCols
     * @param list<mixed>  $updateVals
     */
    private function upsertRow(
        AbstractPlatform $platform,
        string $table,
        array $pkCols,
        array $insertCols,
        array $insertVals,
        array $updateCols,
        array $updateVals,
    ): void {
        $conn = $this->em->getConnection();

        $qid = fn(string $c) => $platform->quoteIdentifier($c);

        $colsList = implode(', ', array_map($qid, $insertCols));
        $phList   = implode(', ', array_fill(0, \count($insertVals), '?'));

        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SqlitePlatform) {
            $pkList = implode(', ', array_map($qid, $pkCols));
            $set    = implode(', ', array_map(
                fn($c) => sprintf('%s = EXCLUDED.%s', $qid($c), $qid($c)),
                $updateCols
            ));

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $qid($table),
                $colsList,
                $phList,
                $pkList,
                $set
            );

            $conn->executeStatement($sql, $insertVals, $this->inferTypes($insertVals));
            return;
        }

        // Fallback: UPDATE first, then INSERT (ignore duplicate)
        $setPairs = implode(', ', array_map(fn($c) => sprintf('%s = ?', $qid($c)), $updateCols));
        $wherePk  = implode(' AND ', array_map(fn($c) => sprintf('%s = ?', $qid($c)), $pkCols));
        $sqlUpdate = sprintf('UPDATE %s SET %s WHERE %s', $qid($table), $setPairs, $wherePk);

        // Build WHERE param values from insertVals by matching pk column positions
        $pkVals = [];
        foreach ($pkCols as $pk) {
            $pos = array_search($pk, $insertCols, true);
            $pkVals[] = $pos === false ? null : $insertVals[$pos];
        }

        $updateParams = array_merge($updateVals, $pkVals);
        $conn->executeStatement($sqlUpdate, $updateParams, $this->inferTypes($updateParams));

        if ($conn->lastInsertId() || $conn->executeStatement('SELECT 1')) {
            // No exact rowcount guarantee cross‑platform; attempt the insert regardless,
            // a duplicate key will be ignored below.
        }

        $sqlInsert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $qid($table), $colsList, $phList);
        try {
            $conn->executeStatement($sqlInsert, $insertVals, $this->inferTypes($insertVals));
        } catch (UniqueConstraintViolationException) {
            // concurrent insert won; ok
        }
    }

    private function inferTypes(array $vals): array
    {
        return array_map(static function ($v) {
            if ($v instanceof \DateTimeImmutable || $v instanceof \DateTimeInterface) {
                return Types::DATETIME_IMMUTABLE;
            }
            if (\is_int($v))   return Types::INTEGER;
            if (\is_bool($v))  return Types::BOOLEAN;
            if (\is_array($v)) return Types::JSON;
            return Types::STRING;
        }, $vals);
    }
}
