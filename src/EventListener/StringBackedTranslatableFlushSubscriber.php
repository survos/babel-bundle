<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Runtime\BabelRuntime;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\HashUtil;

/**
 * Collects string-backed translatable values during onFlush and writes them
 * into STR + STR_TRANSLATION tables during postFlush.
 */
final class StringBackedTranslatableFlushSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * @return string[]
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
            Events::postFlush,
        ];
    }

    /**
     * @var array<string, array{
     *     original:string,
     *     src:string,
     *     targetLocales:?array
     * }> keyed by STR.hash
     */
    private array $pending = [];

    /** @var list<array{str_hash:string, locale:string, text:string}> */
    private array $pendingWithText = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending         = [];
        $this->pendingWithText = [];

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $insertions = $uow->getScheduledEntityInsertions();
        $updates    = $uow->getScheduledEntityUpdates();

        // Always log something so we know this is wired
        $this->logger->info('Babel onFlush: starting collection', [
            'insertions' => \count($insertions),
            'updates'    => \count($updates),
        ]);

        foreach (\array_merge($insertions, $updates) as $entity) {
            $this->collectFromEntity($entity, 'onFlush');
        }

        if ($this->debug) {
            $this->logger->info('Babel onFlush: collection summary', [
                'pending_str' => \count($this->pending),
                'pending_tr'  => \count($this->pendingWithText),
            ]);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Again, always log so we can see the subscriber firing
        $this->logger->info('Babel postFlush: invoked', [
            'pending_str' => \count($this->pending),
            'pending_tr'  => \count($this->pendingWithText),
        ]);

        if ($this->pending === [] && $this->pendingWithText === []) {
            if ($this->debug) {
                $this->logger->info('Babel postFlush: nothing pending, skipping.');
            }
            return;
        }

        $em   = $args->getObjectManager();
        $conn = $em->getConnection();
        $pf   = $conn->getDatabasePlatform();

        $strTable = BabelRuntime::STRING_TABLE;       // 'str'
        $trTable  = BabelRuntime::TRANSLATION_TABLE;  // 'str_translation'
        $nowFn    = $pf instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';

        if ($this->debug) {
            $this->logger->info('Babel postFlush: preparing SQL', [
                'str_table'   => $strTable,
                'tr_table'    => $trTable,
                'db_platform' => $pf::class,
            ]);
        }

        $sqlStr      = $this->buildSqlStr($pf, $strTable, $nowFn);
        $sqlTrEnsure = $this->buildSqlTrEnsure($pf, $trTable, $nowFn);
        $sqlTrUpsert = $this->buildSqlTrUpsert($pf, $trTable, $nowFn);

        $started = false;

        try {
            if (!$conn->isTransactionActive()) {
                $conn->beginTransaction();
                $started = true;
            }

            // Phase 1: STR upserts
            foreach ($this->pending as $strHash => $row) {
                $this->executeStrUpsert(
                    $conn,
                    $pf,
                    $sqlStr,
                    $strTable,
                    $nowFn,
                    $strHash,
                    $row['original'],
                    $row['src'],
                );
            }

            // Phase 2: TR ensure (stubs)
            foreach ($this->pending as $strHash => $row) {
                $targetLocales = $row['targetLocales'] ?? null;

                if ($targetLocales === []) {
                    if ($this->debug) {
                        $this->logger->info('Babel TR_ENSURE: skipping (targetLocales=[])', [
                            'str_hash' => $strHash,
                        ]);
                    }
                    continue;
                }

                $locales = $targetLocales ?? ($this->locale->getEnabled() ?: [$this->locale->getDefault()]);

                foreach ($locales as $loc) {
                    $trHash = HashUtil::calcTranslationKey($strHash, (string) $loc);
                    $params = [
                        'hash'     => $trHash,
                        'str_hash' => $strHash,
                        'locale'   => (string) $loc,
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
                }
            }

            // Phase 3: TR upserts (text)
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

            if ($started) {
                $conn->commit();
            }

            if ($this->debug) {
                $this->logger->info('Babel postFlush: completed successfully', [
                    'str_rows' => \count($this->pending),
                    'tr_rows'  => \count($this->pendingWithText),
                ]);
            }
        } catch (\Throwable $e) {
            if ($started && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            $this->logger->error('Babel postFlush failed (global)', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        } finally {
            $this->pending         = [];
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
                      original   = VALUES(original),
                      src_locale = VALUES(src_locale),
                      updated_at = {$nowFn}";
        }

        throw new \RuntimeException('Unsupported DB platform for STR upsert: ' . $pf::class);
    }

    private function buildSqlTrEnsure(object $pf, string $trTable, string $nowFn): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                    ON CONFLICT DO NOTHING";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                    ON CONFLICT DO NOTHING";
        }

        return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, NULL, {$nowFn}, {$nowFn})
                ON DUPLICATE KEY UPDATE hash = hash";
    }

    private function buildSqlTrUpsert(object $pf, string $trTable, string $nowFn): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                    ON CONFLICT (hash) DO UPDATE
                      SET text      = EXCLUDED.text,
                          str_hash  = EXCLUDED.str_hash,
                          locale    = EXCLUDED.locale,
                          updated_at = {$nowFn}";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                    ON CONFLICT(hash) DO UPDATE SET
                      text      = excluded.text,
                      str_hash  = excluded.str_hash,
                      locale    = excluded.locale,
                      updated_at = {$nowFn}";
        }

        return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                ON DUPLICATE KEY UPDATE
                  text      = VALUES(text),
                  str_hash  = VALUES(str_hash),
                  locale    = VALUES(locale),
                  updated_at = {$nowFn}";
    }

    // === Exec helpers, collect, etc. =========================================

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
        $params = [
            'hash'     => $strHash,
            'original' => $original,
            'src'      => $src,
        ];

        try {
            $conn->executeStatement($sqlStr, $params);
        } catch (\Throwable $e) {
            if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                $ins = "INSERT OR IGNORE INTO {$strTable} (hash, original, src_locale, created_at, updated_at)
                        VALUES (:hash, :original, :src, {$nowFn}, {$nowFn})";
                $upd = "UPDATE {$strTable}
                        SET original = :original, src_locale = :src, updated_at = {$nowFn}
                        WHERE hash = :hash";

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
                WHERE hash = :hash";

        $conn->executeStatement($ins, $params);
    }

    private function collectFromEntity(object $entity, string $phase): int
    {
        $class = $entity::class;

        if ($this->debug) {
            $this->logger->info('Babel collect: inspecting entity', [
                'class' => $class,
                'phase' => $phase,
            ]);
        }

        // 1) Try compile-time index
        $fields = $this->index->fieldsFor($class);

        // 2) Fallback: runtime attribute scan
        if ($fields === []) {
            $fields = $this->discoverTranslatableFieldsFallback($entity);

            if ($this->debug) {
                $this->logger->info('Babel collect: fallback attribute scan', [
                    'class'       => $class,
                    'field_count' => \count($fields),
                    'fields'      => $fields,
                ]);
            }

            if ($fields === []) {
                return 0;
            }
        }

        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel collect: entity missing hooks API; skipping.', [
                'class' => $class,
                'phase' => $phase,
            ]);

            return 0;
        }

        $srcLocale    = $this->resolveSourceLocale($entity, $class);
        $cfg          = $this->index->configFor($class) ?? [];
        $fieldCfg     = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
        $classTargets = $cfg['targetLocales'] ?? null;

        $count = 0;

        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            $context = $fieldCfg[$field]['context'] ?? null;

            $strHash = HashUtil::calcSourceKey($original, $srcLocale);

            $this->pending[$strHash] = [
                'original'      => $original,
                'src'           => $srcLocale,
                'targetLocales' => $classTargets,
            ];

            if ($this->debug) {
                $this->logger->info('Babel collect: queued STR row', [
                    'class'     => $class,
                    'field'     => $field,
                    'srcLocale' => $srcLocale,
                    'str_hash'  => $strHash,
                    'context'   => $context,
                ]);
            }

            // pending TR with explicit text
            if (\property_exists($entity, '_pendingTranslations') && \is_array($entity->_pendingTranslations ?? null)) {
                $pairs = $entity->_pendingTranslations[$field] ?? null;

                if (\is_array($pairs)) {
                    foreach ($pairs as $loc => $txt) {
                        if (!\is_string($loc) || !\is_string($txt) || $txt === '') {
                            continue;
                        }

                        $this->pendingWithText[] = [
                            'str_hash' => $strHash,
                            'locale'   => (string) $loc,
                            'text'     => $txt,
                        ];

                        if ($this->debug) {
                            $this->logger->info('Babel collect: queued TR row with text', [
                                'class'    => $class,
                                'field'    => $field,
                                'locale'   => $loc,
                                'str_hash' => $strHash,
                            ]);
                        }
                    }
                }
            }

            $count++;
        }

        if ($this->debug && $count === 0) {
            $this->logger->info('Babel collect: no non-empty strings found on entity', [
                'class' => $class,
                'phase' => $phase,
            ]);
        }

        return $count;
    }

    private function resolveSourceLocale(object $entity, string $class): string
    {
        $cfg = $this->index->configFor($class) ?? [];

        if (\is_string($cfg['sourceLocale'] ?? null) && $cfg['sourceLocale'] !== '') {
            return $cfg['sourceLocale'];
        }

        $acc = $this->index->localeAccessorFor($class);
        if ($acc) {
            if ($acc['type'] === 'prop' && \property_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']} ?? null;
                if (\is_string($v) && $v !== '') {
                    return $v;
                }
            }

            if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']}();
                if (\is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }

        return $this->locale->getDefault();
    }

    /**
     * Fallback: scan entity properties for #[Translatable].
     *
     * @return list<string>
     */
    private function discoverTranslatableFieldsFallback(object $entity): array
    {
        $rc  = new \ReflectionClass($entity);
        $out = [];

        foreach ($rc->getProperties() as $prop) {
            if ($prop->getAttributes(Translatable::class) !== []) {
                $out[] = $prop->getName();
            }
        }

        return $out;
    }

    private function isSqliteConflictTargetError(\Throwable $e): bool
    {
        return \str_contains(
            $e->getMessage(),
            'ON CONFLICT clause does not match any PRIMARY KEY or UNIQUE constraint'
        );
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
}
