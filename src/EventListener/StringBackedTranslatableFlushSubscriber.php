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
use Survos\BabelBundle\Service\TargetLocaleResolver;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

final class StringBackedTranslatableFlushSubscriber implements EventSubscriber
{
    public const BABEL_ENGINE = 'babel';

    /**
     * @var array<string, array{
     *     original:string,
     *     src:string,
     *     targetLocales:list<string>
     * }> keyed by STR.hash
     */
    private array $pending = [];

    /** @var list<array{str_hash:string, locale:string, text:string}> */
    private array $pendingWithText = [];

    private bool $inPostFlush = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $locale,
        private readonly TranslatableIndex $index,
        private readonly TargetLocaleResolver $targetLocaleResolver,
        private readonly bool $debug = false,
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending         = [];
        $this->pendingWithText = [];

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $insertions = $uow->getScheduledEntityInsertions();
        $updates    = $uow->getScheduledEntityUpdates();

        foreach (\array_merge($insertions, $updates) as $entity) {
            $this->collectFromEntity($entity, 'onFlush');
        }

        if ($this->debug) {
            $this->logger->debug('Babel onFlush: collected', [
                'insertions'  => \count($insertions),
                'updates'     => \count($updates),
                'pending_str' => \count($this->pending),
                'pending_tr'  => \count($this->pendingWithText),
            ]);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->inPostFlush) {
            return;
        }

        if ($this->pending === [] && $this->pendingWithText === []) {
            return;
        }

        $this->inPostFlush = true;

        $em   = $args->getObjectManager();
        $conn = $em->getConnection();
        $pf   = $conn->getDatabasePlatform();

        $strTable = BabelRuntime::STRING_TABLE;
        $trTable  = BabelRuntime::TRANSLATION_TABLE;
        $nowFn    = $pf instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';

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
                $locales = $row['targetLocales'];

                // If none, nothing to ensure.
                if ($locales === []) {
                    continue;
                }

                foreach ($locales as $locRaw) {
                    $loc = HashUtil::normalizeLocale((string) $locRaw);

                    $trHash = HashUtil::calcTranslationKey($strHash, $loc, self::BABEL_ENGINE);

                    $params = [
                        'hash'     => $trHash,
                        'str_hash' => $strHash,
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
                }
            }

            // Phase 3: TR upserts (text)
            foreach ($this->pendingWithText as $r) {
                $strHash = $r['str_hash'];
                $loc     = HashUtil::normalizeLocale($r['locale']);

                $trHash  = HashUtil::calcTranslationKey($strHash, $loc, self::BABEL_ENGINE);

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

            $this->logger->info('Babel postFlush: persisted translatable strings', [
                'str_rows' => \count($this->pending),
                'tr_rows'  => \count($this->pendingWithText),
                'platform' => $pf::class,
            ]);
        } catch (\Throwable $e) {
            if ($started && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            $this->logger->error('Babel postFlush failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        } finally {
            $this->pending         = [];
            $this->pendingWithText = [];
            $this->inPostFlush      = false;
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
                      SET text       = EXCLUDED.text,
                          str_hash   = EXCLUDED.str_hash,
                          locale     = EXCLUDED.locale,
                          updated_at = {$nowFn}";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                    VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                    ON CONFLICT(hash) DO UPDATE SET
                      text       = excluded.text,
                      str_hash   = excluded.str_hash,
                      locale     = excluded.locale,
                      updated_at = {$nowFn}";
        }

        return "INSERT INTO {$trTable} (hash, str_hash, locale, text, created_at, updated_at)
                VALUES (:hash, :str_hash, :locale, :text, {$nowFn}, {$nowFn})
                ON DUPLICATE KEY UPDATE
                  text       = VALUES(text),
                  str_hash   = VALUES(str_hash),
                  locale     = VALUES(locale),
                  updated_at = {$nowFn}";
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
        $conn->executeStatement($upd, $params);
    }

    // === Collection ==========================================================

    private function collectFromEntity(object $entity, string $phase): int
    {
        $class = $entity::class;

        $fields = $this->index->fieldsFor($class);

        if ($fields === []) {
            $fields = $this->discoverTranslatableFieldsFallback($entity);
            if ($fields === []) {
                return 0;
            }
        }

        if (!\method_exists($entity, 'getBackingValue')) {
            if ($this->debug) {
                $this->logger->debug('Babel collect: entity missing getBackingValue(); skipping', [
                    'class' => $class,
                    'phase' => $phase,
                ]);
            }
            return 0;
        }

        $srcLocale    = HashUtil::normalizeLocale($this->resolveSourceLocale($entity, $class));
        $cfg          = $this->index->configFor($class) ?? [];
        $classTargets = $cfg['targetLocales'] ?? null;

        $enabled = $this->locale->getEnabled();
        if ($enabled === []) {
            $enabled = [$this->locale->getDefault()];
        }

        $resolvedTargets = $this->targetLocaleResolver->resolve(
            enabledLocales: $enabled,
            explicitTargets: \is_array($classTargets) ? $classTargets : null,
            sourceLocale: $srcLocale,
        );

        $count = 0;

        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            $strHash = HashUtil::calcSourceKey($original, $srcLocale);

            $this->pending[$strHash] = [
                'original'      => $original,
                'src'           => $srcLocale,
                'targetLocales' => $resolvedTargets,
            ];

            if (\property_exists($entity, '_pendingTranslations') && \is_array($entity->_pendingTranslations ?? null)) {
                $pairs = $entity->_pendingTranslations[$field] ?? null;

                if (\is_array($pairs)) {
                    foreach ($pairs as $loc => $txt) {
                        $loc = HashUtil::normalizeLocale((string) $loc);
                        if ($loc === '' || !\is_string($txt) || $txt === '') {
                            continue;
                        }

                        $this->pendingWithText[] = [
                            'str_hash' => $strHash,
                            'locale'   => $loc,
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

    /** @return list<string> */
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
