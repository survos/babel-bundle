<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Runtime\BabelRuntime;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\HashUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
        #[Autowire('%kernel.debug%')] private readonly bool $debug = false,
    ) {}

    /** @var array<string, array{original:string, src:string}> keyed by STR.hash */
    private array $pending = [];

    /** @var list<array{str_hash:string, locale:string, text:string}> */
    private array $pendingWithText = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending = [];
        $this->pendingWithText = [];

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $collected = 0;
        foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $entity) {
            $collected += $this->collectFromEntity($entity, 'onFlush');
        }

        if ($this->debug && $this->pending) {
            // Log a couple of computed keys
            $sample = array_slice($this->pending, 0, 3, true);
            $this->logger->debug('Babel key sample (STR)', ['sample' => array_keys($sample)]);
        }

        $this->logger->info('Babel onFlush collected', [
            'entities' => $collected,
            'pending'  => \count($this->pending),
            'texts'    => \count($this->pendingWithText),
        ]);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === [] && $this->pendingWithText === []) {
            return;
        }

        $em       = $args->getObjectManager();
        $conn     = $em->getConnection();
        $pf       = $conn->getDatabasePlatform();

        $strTable = BabelRuntime::STRING_TABLE;        // 'str'
        $trTable  = BabelRuntime::TRANSLATION_TABLE;   // 'str_translation'
        $nowFn    = $pf instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';

        // SQL per platform
        $sqlStr      = $this->buildSqlStr($pf, $strTable, $nowFn);
        $sqlTrEnsure = $this->buildSqlTrEnsure($pf, $trTable, $nowFn); // (str_hash, locale) conflict
        $sqlTrUpsert = $this->buildSqlTrUpsert($pf, $trTable, $nowFn); // (str_hash, locale) conflict

        if ($this->debug) {
            $this->logSqliteDiagnostics($conn, $pf, $strTable, $trTable);
        }

        $locales = $this->locale->getEnabled() ?: [$this->locale->getDefault()];
        \assert($locales !== [], 'Babel: enabled locales must not be empty');

        $started = false;
        try {
            if (!$conn->isTransactionActive()) {
                $conn->beginTransaction();
                $started = true;
            }

            // Phase 1: STR upserts
            foreach ($this->pending as $strHash => $row) {
                $this->executeStrUpsert($conn, $pf, $sqlStr, $strTable, $nowFn, $strHash, $row['original'], $row['src']);
            }
            if ($this->debug) {
                $this->logger->debug('Babel phase complete: STR upserts', ['count' => \count($this->pending)]);
            }

            // Phase 2: TR ensure (stubs) using correct TR key convention
            $trKeySamples = [];
            foreach ($this->pending as $strHash => $_) {
                foreach ($locales as $loc) {
                    $loc    = (string)$loc;
                    $trHash = HashUtil::calcTranslationKey($strHash, $loc);
                    $params = [
                        'hash'     => $trHash,    // TR PK (convention: "<strHash>-<locale>")
                        'str_hash' => $strHash,   // for conflict key + query convenience
                        'locale'   => $loc,
                    ];
                    try {
                        $conn->executeStatement($sqlTrEnsure, $params);
                    } catch (\Throwable $e) {
                        if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                            $this->sqliteTrEnsureFallback($conn, $trTable, $params, $nowFn);
                        } else {
                            $this->logPhaseError('TR_ENSURE', $sqlTrEnsure, $params, $e);
                        }
                    }
                    if ($this->debug && \count($trKeySamples) < 3) {
                        $trKeySamples[] = $params;
                    }
                }
            }
            if ($this->debug && $trKeySamples) {
                $this->logger->debug('Babel key sample (TR)', ['sample' => $trKeySamples]);
            }
            if ($this->debug) {
                $this->logger->debug('Babel phase complete: TR ensure', [
                    'strs' => \count($this->pending),
                    'locales' => \count($locales)
                ]);
            }

            // Phase 3: TR upserts (texts), same TR key convention
            foreach ($this->pendingWithText as $r) {
                $strHash = $r['str_hash'];
                $loc     = $r['locale'];
                $trHash  = HashUtil::calcTranslationKey($strHash, $loc);

                $params = [
                    'hash'     => $trHash,
                    'str_hash' => $strHash,
                    'locale'   => $loc,
                    'text'     => $r['text'],
                ];

                try {
                    $conn->executeStatement($sqlTrUpsert, $params);
                } catch (\Throwable $e) {
                    if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                        $this->sqliteTrUpsertFallback($conn, $trTable, $params, $nowFn);
                    } else {
                        $this->logPhaseError('TR_UPSERT', $sqlTrUpsert, $params, $e);
                        throw $e;
                    }
                }
            }
            if ($this->debug) {
                $this->logger->debug('Babel phase complete: TR upserts', ['count' => \count($this->pendingWithText)]);
            }

            if ($started) {
                $conn->commit();
            }

            $this->logger->info('Babel postFlush finished', [
                'str'      => \count($this->pending),
                'tr_texts' => \count($this->pendingWithText),
            ]);
        } catch (\Throwable $e) {
            if ($started && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('Babel postFlush failed (global)', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        } finally {
            $this->pending = [];
            $this->pendingWithText = [];
        }
    }

    // === SQL builders ========================================================

    private function buildSqlStr(object $pf, string $strTable, string $nowFn): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                    VALUES (:hash, :original, :src, {$nowFn}, {$nowFn})
                    ON CONFLICT (hash) DO UPDATE
                      SET original = EXCLUDED.original,
                          src_locale = EXCLUDED.src_locale,
                          updated_at = {$nowFn}";
        }
        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                    VALUES (:hash, :original, :src, {$nowFn}, {$nowFn})
                    ON CONFLICT(hash) DO UPDATE SET
                      original = excluded.original,
                      src_locale = excluded.src_locale,
                      updated_at = {$nowFn}";
        }
        if ($pf instanceof MySQLPlatform || $pf instanceof MariaDBPlatform) {
            return "INSERT INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                    VALUES (:hash, :original, :src, {$nowFn}, {$nowFn})
                    ON DUPLICATE KEY UPDATE
                      original = VALUES(original),
                      src_locale = VALUES(src_locale),
                      updated_at = {$nowFn}";
        }
        throw new \RuntimeException('Unsupported DB platform for STR upsert: '.$pf::class);
    }

    private function buildSqlTrEnsure(object $pf, string $trTable, string $nowFn): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                    ON CONFLICT (str_hash, locale) DO NOTHING";
        }
        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                    ON CONFLICT(str_hash, locale) DO NOTHING";
        }
        return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                ON DUPLICATE KEY UPDATE text = text";
    }

    private function buildSqlTrUpsert(object $pf, string $trTable, string $nowFn): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                    ON CONFLICT (str_hash, locale) DO UPDATE
                      SET text = EXCLUDED.text, updated_at = {$nowFn}";
        }
        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                    ON CONFLICT(str_hash, locale) DO UPDATE SET
                      text = excluded.text, updated_at = {$nowFn}";
        }
        return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                ON DUPLICATE KEY UPDATE
                  text = VALUES(text), updated_at = {$nowFn}";
    }

    // === Exec helpers ========================================================

    private function executeStrUpsert(
        Connection $conn,
        object $pf,
        string $sqlStr,
        string $strTable,
        string $nowFn,
        string $strHash,
        string $original,
        string $src,
    ): void {
        $params = ['hash' => $strHash, 'original' => $original, 'src' => $src];

        try {
            $conn->executeStatement($sqlStr, $params);
        } catch (\Throwable $e) {
            if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                $ins = "INSERT OR IGNORE INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                        VALUES (:hash, :original, :src, {$nowFn}, {$nowFn})";
                $upd = "UPDATE {$strTable}
                        SET original = :original, src_locale = :src, updated_at = {$nowFn}
                        WHERE hash = :hash";
                if ($this->debug) {
                    $this->logger->warning('Babel STR upsert fallback (SQLite)', [
                        'hash' => $strHash,
                        'reason' => $e->getMessage(),
                    ]);
                }
                $conn->executeStatement($ins, $params);
                $conn->executeStatement($upd, $params);
                return;
            }
            $this->logPhaseError('STR_UPSERT', $sqlStr, $params, $e);
            throw $e;
        }
    }

    private function sqliteTrEnsureFallback(Connection $conn, string $trTable, array $params, string $nowFn): void
    {
        $ins = "INSERT OR IGNORE INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})";
        $conn->executeStatement($ins, $params);
    }

    private function sqliteTrUpsertFallback(Connection $conn, string $trTable, array $params, string $nowFn): void
    {
        $ins = "INSERT OR IGNORE INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})";
        $upd = "UPDATE {$trTable}
                SET text = :text, updated_at = {$nowFn}
                WHERE str_hash = :str_hash AND locale = :locale";
        if ($this->debug) {
            $this->logger->warning('Babel TR upsert fallback (SQLite)', [
                'str_hash' => $params['str_hash'] ?? null,
                'locale'   => $params['locale'] ?? null,
            ]);
        }
        $conn->executeStatement($ins, $params);
        $conn->executeStatement($upd, $params);
    }

    // === Collect / hashing ===================================================

    private function collectFromEntity(object $entity, string $phase): int
    {
        $class  = $entity::class;
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            return 0;
        }

        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel collect: entity missing hooks API; skipping.', ['class' => $class, 'phase' => $phase]);
            return 0;
        }

        $srcLocale = $this->resolveSourceLocale($entity, $class);
        \assert($srcLocale !== '', 'Babel: srcLocale must not be empty during hashing');

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        $count = 0;
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }
            $context = $fieldCfg[$field]['context'] ?? null;

            // Use *your* canonical source key
            $strHash = HashUtil::calcSourceKey($original, $srcLocale);

            if ($this->debug) {
                $this->logger->debug('Babel.collect', [
                    'phase' => $phase,
                    'class' => $class,
                    'field' => $field,
                    'src'   => $srcLocale,
                    'ctx'   => $context,
                    'hash'  => $strHash,
                ]);
            }

            $this->pending[$strHash] = [
                'original' => $original,
                'src'      => $srcLocale,
            ];

            if (\property_exists($entity, '_pendingTranslations') && \is_array($entity->_pendingTranslations ?? null)) {
                $pairs = $entity->_pendingTranslations[$field] ?? null;
                if (\is_array($pairs)) {
                    foreach ($pairs as $loc => $txt) {
                        if (!\is_string($loc) || !\is_string($txt) || $txt === '') {
                            continue;
                        }
                        $this->pendingWithText[] = [
                            'str_hash' => $strHash,
                            'locale'   => (string)$loc,
                            'text'     => $txt,
                        ];
                    }
                }
            }

            $count++;
        }

        return $count;
    }

    private function resolveSourceLocale(object $entity, string $class): string
    {
        $acc = $this->index->localeAccessorFor($class);
        if ($acc) {
            if ($acc['type'] === 'prop' && \property_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']} ?? null;
                if (\is_string($v) && $v !== '') return $v;
            }
            if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']}();
                if (\is_string($v) && $v !== '') return $v;
            }
        }
        return $this->locale->getDefault();
    }

    // === Debug helpers =======================================================

    private function isSqliteConflictTargetError(\Throwable $e): bool
    {
        return \str_contains($e->getMessage(), 'ON CONFLICT clause does not match any PRIMARY KEY or UNIQUE constraint');
    }

    private function logPhaseError(string $phase, string $sql, array $params, \Throwable $e): void
    {
        $this->logger->error("Babel phase failed: {$phase}", [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'sql'       => $sql,
            'params'    => $params,
        ]);
    }

    private function logSqliteDiagnostics(Connection $conn, object $pf, string $strTable, string $trTable): void
    {
        if (!$pf instanceof SqlitePlatform) {
            return;
        }
        try {
            $ver = $this->fetchOneScalar($conn, 'SELECT sqlite_version();');
            $db  = $this->fetchAllRows($conn, 'PRAGMA database_list;');
            $strIdx = $this->fetchAllRows($conn, "PRAGMA index_list('{$strTable}');");
            $trIdx  = $this->fetchAllRows($conn, "PRAGMA index_list('{$trTable}');");
            $strDDL = $this->fetchAllRows($conn,
                "SELECT name, sql FROM sqlite_master WHERE type IN ('table','index') AND tbl_name = '{$strTable}' ORDER BY name;"
            );
            $trDDL = $this->fetchAllRows($conn,
                "SELECT name, sql FROM sqlite_master WHERE type IN ('table','index') AND tbl_name = '{$trTable}' ORDER BY name;"
            );

            $this->logger->debug('Babel DB diagnostics', [
                'platform' => $pf::class,
                'sqlite_ver' => $ver,
                'database_list' => $db,
                'str.index_list' => $strIdx,
                'tr.index_list'  => $trIdx,
                'str.sqlite_master' => $strDDL,
                'tr.sqlite_master'  => $trDDL,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Babel diagnostics failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchAllRows(Connection $conn, string $sql): array
    {
        return $conn->executeQuery($sql)->fetchAllAssociative();
    }

    private function fetchOneScalar(Connection $conn, string $sql): ?string
    {
        $v = $conn->executeQuery($sql)->fetchOne();
        return $v === false ? null : (string) $v;
    }
}
