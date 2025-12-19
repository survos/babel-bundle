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
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TargetLocaleResolver;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

final class StringBackedTranslatableFlushSubscriber implements EventSubscriber
{
    public const string BABEL_ENGINE = 'babel';

    /**
     * @var array<string, array{
     *     source:string,
     *     sourceLocale:string,
     *     context:?string,
     *     targetLocales:list<string>,
     *     meta:array
     * }> keyed by str.code
     */
    private array $pending = [];

    /** @var list<array{str_code:string, target_locale:string, engine:?string, text:string}> */
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

        foreach (\array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        ) as $entity) {
            $this->collectFromEntity($entity, 'onFlush');
        }

        if ($this->debug) {
            $this->logger->debug('Babel onFlush: collected', [
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

        $strTable = BabelSchema::STRING_TABLE;          // "str"
        $trTable  = BabelSchema::TRANSLATION_TABLE;     // "str_tr"

        $sqlStrUpsert = $this->buildSqlStrUpsert($pf, $strTable);
        $sqlTrEnsure  = $this->buildSqlTrEnsure($pf, $trTable);
        $sqlTrUpsert  = $this->buildSqlTrUpsert($pf, $trTable);

        $started = false;

        try {
            if (!$conn->isTransactionActive()) {
                $conn->beginTransaction();
                $started = true;
            }

            // Phase 1: STR upserts
            foreach ($this->pending as $code => $row) {
                $params = [
                    'code'          => $code,
                    'source_locale' => $row['sourceLocale'],
                    'source'        => $row['source'],
                    'context'       => $row['context'],
                    'meta'          => \json_encode($row['meta'], JSON_THROW_ON_ERROR),
                ];

                try {
                    $conn->executeStatement($sqlStrUpsert, $params);
                } catch (\Throwable $e) {
                    if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                        $this->sqliteStrUpsertFallback($conn, $strTable, $params);
                    } else {
                        $this->logPhaseError('STR_UPSERT', $sqlStrUpsert, $params, $e);
                        throw $e;
                    }
                }
            }

            // Phase 2: TR ensure (stubs)
            foreach ($this->pending as $code => $row) {
                foreach ($row['targetLocales'] as $locRaw) {
                    $loc = HashUtil::normalizeLocale((string) $locRaw);
                    if ($loc === '') {
                        continue;
                    }

                    $params = [
                        'str_code'      => $code,
                        'target_locale' => $loc,
                        'engine'        => self::BABEL_ENGINE,
                        'meta'          => \json_encode([], JSON_THROW_ON_ERROR),
                    ];

                    try {
                        $conn->executeStatement($sqlTrEnsure, $params);
                    } catch (\Throwable $e) {
                        if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                            $this->sqliteTrEnsureFallback($conn, $trTable, $params);
                        } else {
                            $this->logPhaseError('TR_ENSURE', $sqlTrEnsure, $params, $e);
                        }
                    }
                }
            }

            // Phase 3: TR upserts (text)
            foreach ($this->pendingWithText as $r) {
                $loc = HashUtil::normalizeLocale($r['target_locale']);
                if ($loc === '' || $r['text'] === '') {
                    continue;
                }

                $params = [
                    'str_code'      => $r['str_code'],
                    'target_locale' => $loc,
                    'engine'        => $r['engine'] ?? self::BABEL_ENGINE,
                    'text'          => $r['text'],
                    'meta'          => \json_encode([], JSON_THROW_ON_ERROR),
                ];

                try {
                    $conn->executeStatement($sqlTrUpsert, $params);
                } catch (\Throwable $e) {
                    if ($pf instanceof SqlitePlatform && $this->isSqliteConflictTargetError($e)) {
                        $this->sqliteTrUpsertFallback($conn, $trTable, $params);
                    } else {
                        $this->logPhaseError('TR_UPSERT', $sqlTrUpsert, $params, $e);
                        throw $e;
                    }
                }
            }

            if ($started) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            $this->logger->error('Babel postFlush failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            if ($this->debug) {
                throw $e;
            }
        } finally {
            $this->pending         = [];
            $this->pendingWithText = [];
            $this->inPostFlush      = false;
        }
    }

    // === SQL builders ========================================================

    private function buildSqlStrUpsert(object $pf, string $strTable): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$strTable} (code, source_locale, source, context, meta)
                    VALUES (:code, :source_locale, :source, :context, :meta::jsonb)
                    ON CONFLICT (code) DO UPDATE
                      SET source_locale = EXCLUDED.source_locale,
                          source       = EXCLUDED.source,
                          context      = EXCLUDED.context,
                          meta         = EXCLUDED.meta";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$strTable} (code, source_locale, source, context, meta)
                    VALUES (:code, :source_locale, :source, :context, :meta)
                    ON CONFLICT(code) DO UPDATE SET
                      source_locale = excluded.source_locale,
                      source       = excluded.source,
                      context      = excluded.context,
                      meta         = excluded.meta";
        }

        if ($pf instanceof MySQLPlatform || $pf instanceof MariaDBPlatform) {
            return "INSERT INTO {$strTable} (code, source_locale, source, context, meta)
                    VALUES (:code, :source_locale, :source, :context, :meta)
                    ON DUPLICATE KEY UPDATE
                      source_locale = VALUES(source_locale),
                      source       = VALUES(source),
                      context      = VALUES(context),
                      meta         = VALUES(meta)";
        }

        throw new \RuntimeException('Unsupported DB platform for STR upsert: ' . $pf::class);
    }

    private function buildSqlTrEnsure(object $pf, string $trTable): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                    VALUES (:str_code, :target_locale, :engine, NULL, :meta::jsonb)
                    ON CONFLICT (str_code, target_locale, engine) DO NOTHING";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                    VALUES (:str_code, :target_locale, :engine, NULL, :meta)
                    ON CONFLICT(str_code, target_locale, engine) DO NOTHING";
        }

        return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                VALUES (:str_code, :target_locale, :engine, NULL, :meta)
                ON DUPLICATE KEY UPDATE str_code = str_code";
    }

    private function buildSqlTrUpsert(object $pf, string $trTable): string
    {
        if ($pf instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                    VALUES (:str_code, :target_locale, :engine, :text, :meta::jsonb)
                    ON CONFLICT (str_code, target_locale, engine) DO UPDATE
                      SET text = EXCLUDED.text,
                          meta = EXCLUDED.meta";
        }

        if ($pf instanceof SqlitePlatform) {
            return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                    VALUES (:str_code, :target_locale, :engine, :text, :meta)
                    ON CONFLICT(str_code, target_locale, engine) DO UPDATE SET
                      text = excluded.text,
                      meta = excluded.meta";
        }

        return "INSERT INTO {$trTable} (str_code, target_locale, engine, text, meta)
                VALUES (:str_code, :target_locale, :engine, :text, :meta)
                ON DUPLICATE KEY UPDATE
                  text = VALUES(text),
                  meta = VALUES(meta)";
    }

    // === SQLite fallbacks =====================================================

    private function sqliteStrUpsertFallback(Connection $conn, string $strTable, array $params): void
    {
        $ins = "INSERT OR IGNORE INTO {$strTable} (code, source_locale, source, context, meta)
                VALUES (:code, :source_locale, :source, :context, :meta)";
        $upd = "UPDATE {$strTable}
                SET source_locale = :source_locale,
                    source       = :source,
                    context      = :context,
                    meta         = :meta
                WHERE code = :code";

        $conn->executeStatement($ins, $params);
        $conn->executeStatement($upd, $params);
    }

    private function sqliteTrEnsureFallback(Connection $conn, string $trTable, array $params): void
    {
        $ins = "INSERT OR IGNORE INTO {$trTable} (str_code, target_locale, engine, text, meta)
                VALUES (:str_code, :target_locale, :engine, NULL, :meta)";
        $conn->executeStatement($ins, $params);
    }

    private function sqliteTrUpsertFallback(Connection $conn, string $trTable, array $params): void
    {
        $ins = "INSERT OR IGNORE INTO {$trTable} (str_code, target_locale, engine, text, meta)
                VALUES (:str_code, :target_locale, :engine, :text, :meta)";
        $upd = "UPDATE {$trTable}
                SET text = :text, meta = :meta
                WHERE str_code = :str_code AND target_locale = :target_locale AND engine = :engine";

        $conn->executeStatement($ins, $params);
        $conn->executeStatement($upd, $params);
    }

    private function isSqliteConflictTargetError(\Throwable $e): bool
    {
        return \str_contains(
            $e->getMessage(),
            'ON CONFLICT clause does not match any PRIMARY KEY or UNIQUE constraint'
        );
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

            $strCode = HashUtil::calcSourceKey($original, $srcLocale);

            $this->pending[$strCode] = [
                'source'        => $original,
                'sourceLocale'  => $srcLocale,
                'context'       => null,
                'targetLocales' => $resolvedTargets,
                'meta'          => [],
            ];

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
