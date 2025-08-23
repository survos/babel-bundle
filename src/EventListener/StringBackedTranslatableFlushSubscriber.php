<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    /** @var array<string, array{original:string, src:string}> keyed by hash */
    private array $pending = [];

    /** @var array<int, array{hash:string, locale:string, text:string}> */
    private array $pendingWithText = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'kernel.default_locale')] private readonly string $defaultLocale = 'en',
        /** @var string[] */
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabledLocales = [],
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $this->pending = [];
        $this->pendingWithText = [];

        $this->collectTranslatables($uow);

        $this->logger->info('Babel onFlush collected translatables', [
            'count_pending' => \count($this->pending),
            'count_texts'   => \count($this->pendingWithText),
        ]);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === [] && $this->pendingWithText === []) {
            $this->logger->debug('Babel postFlush: nothing pending; skipping.');
            return;
        }

        $em       = $args->getObjectManager();
        $conn     = $em->getConnection();
        $platform = $conn->getDatabasePlatform();

        // Resolve metadata (real table/column names & nullability)
        $strMeta = $em->getClassMetadata(Str::class);
        $trMeta  = $em->getClassMetadata(StrTranslation::class);

        $strTable = $strMeta->getTableName();
        $trTable  = $trMeta->getTableName();

        $col = static function($meta, string $field): ?string {
            return $meta->hasField($field) ? $meta->getColumnName($field) : null;
        };
        $nullable = static function($meta, string $field): bool {
            if (!$meta->hasField($field)) return true;
            $m = $meta->getFieldMapping($field);
            return $m['nullable'] ?? true;
        };

        // Canonical columns (always exist)
        $strHashCol = $col($strMeta, 'hash') ?? 'hash';
        $strOrigCol = $col($strMeta, 'original') ?? 'original';
        $strSrcCol  = $col($strMeta, 'srcLocale') ?? 'src_locale';

        $trHashCol = $col($trMeta, 'hash') ?? 'hash';
        $trLocCol  = $col($trMeta, 'locale') ?? 'locale';
        $trTextCol = $col($trMeta, 'text') ?? 'text';

        // Optional timestamp/status columns (add if present)
        $strCreatedCol = $col($strMeta, 'createdAt');
        $strUpdatedCol = $col($strMeta, 'updatedAt');

        $trCreatedCol = $col($trMeta, 'createdAt');
        $trUpdatedCol = $col($trMeta, 'updatedAt');
        $trStatusCol  = $col($trMeta, 'status');

        // NOT NULL detection to help logs
        $strCreatedNN = $strCreatedCol ? !$nullable($strMeta, 'createdAt') : false;
        $strUpdatedNN = $strUpdatedCol ? !$nullable($strMeta, 'updatedAt') : false;
        $trCreatedNN  = $trCreatedCol ? !$nullable($trMeta, 'createdAt') : false;
        $trUpdatedNN  = $trUpdatedCol ? !$nullable($trMeta, 'updatedAt') : false;

        $this->logger->info('Babel postFlush starting', [
            'platform'        => $platform::class,
            'str_table'       => $strTable,
            'tr_table'        => $trTable,
            'pending_count'   => \count($this->pending),
            'pending_texts'   => \count($this->pendingWithText),
            'enabled_locales' => $this->enabledLocales,
            'default_locale'  => $this->defaultLocale,
            'columns' => [
                'str' => [
                    'hash'      => $strHashCol,
                    'original'  => $strOrigCol,
                    'src'       => $strSrcCol,
                    'createdAt' => $strCreatedCol,
                    'updatedAt' => $strUpdatedCol,
                    'createdNN' => $strCreatedNN,
                    'updatedNN' => $strUpdatedNN,
                ],
                'tr' => [
                    'hash'      => $trHashCol,
                    'locale'    => $trLocCol,
                    'text'      => $trTextCol,
                    'createdAt' => $trCreatedCol,
                    'updatedAt' => $trUpdatedCol,
                    'status'    => $trStatusCol,
                    'createdNN' => $trCreatedNN,
                    'updatedNN' => $trUpdatedNN,
                ],
            ],
        ]);

        // Platform time expression
        $nowExpr = $platform instanceof SqlitePlatform ? 'CURRENT_TIMESTAMP' : 'NOW()';

        // Build STR upsert SQL dynamically (include created/updated if present)
        $strInsertCols = [$strHashCol, $strOrigCol, $strSrcCol];
        $strInsertVals = [':hash', ':original', ':src'];

        if ($strCreatedCol) { $strInsertCols[] = $strCreatedCol; $strInsertVals[] = $nowExpr; }
        if ($strUpdatedCol) { $strInsertCols[] = $strUpdatedCol; $strInsertVals[] = $nowExpr; }

        $strInsert = "INSERT INTO {$strTable} (" . implode(', ', $strInsertCols) . ") VALUES (" . implode(', ', $strInsertVals) . ")";

        $strUpdateSets = [$strOrigCol . ' = EXCLUDED.' . $strOrigCol, $strSrcCol . ' = EXCLUDED.' . $strSrcCol];
        if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            // MySQL uses VALUES() instead of EXCLUDED
            $strUpdateSets = [$strOrigCol . ' = VALUES(' . $strOrigCol . ')', $strSrcCol . ' = VALUES(' . $strSrcCol . ')'];
        }
        if ($strUpdatedCol) {
            $strUpdateSets[] = $strUpdatedCol . ' = ' . $nowExpr;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $sqlStr = $strInsert . " ON CONFLICT ({$strHashCol}) DO UPDATE SET " . implode(', ', $strUpdateSets);
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlStr = $strInsert . " ON CONFLICT({$strHashCol}) DO UPDATE SET " . implode(', ', array_map(
                    static fn($set) => str_replace(['EXCLUDED.'], ['excluded.'], $set), $strUpdateSets
                ));
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            $sqlStr = $strInsert . " ON DUPLICATE KEY UPDATE " . implode(', ', $strUpdateSets);
        } else {
            $this->logger->error('Unsupported DB platform for STR', ['platform' => $platform::class]);
            return;
        }

        // Build TR ensure & upsert SQL (include created/updated/status if present)
        $trEnsureCols = [$trHashCol, $trLocCol, $trTextCol];
        $trEnsureVals = [':hash', ':locale', 'NULL'];

        if ($trCreatedCol) { $trEnsureCols[] = $trCreatedCol; $trEnsureVals[] = $nowExpr; }
        if ($trUpdatedCol) { $trEnsureCols[] = $trUpdatedCol; $trEnsureVals[] = $nowExpr; }
        if ($trStatusCol)  { $trEnsureCols[] = $trStatusCol;  $trEnsureVals[] = "'untranslated'"; }

        $trEnsureInsert = "INSERT INTO {$trTable} (" . implode(', ', $trEnsureCols) . ") VALUES (" . implode(', ', $trEnsureVals) . ")";

        if ($platform instanceof PostgreSQLPlatform) {
            $sqlTrEnsure = $trEnsureInsert . " ON CONFLICT ({$trHashCol}, {$trLocCol}) DO NOTHING";
        } elseif ($platform instanceof SqlitePlatform) {
            $sqlTrEnsure = $trEnsureInsert . " ON CONFLICT({$trHashCol}, {$trLocCol}) DO NOTHING";
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            $sqlTrEnsure = $trEnsureInsert . " ON DUPLICATE KEY UPDATE {$trTextCol} = {$trTextCol}";
        } else {
            $this->logger->error('Unsupported DB platform for TR ensure', ['platform' => $platform::class]);
            return;
        }

        // TR upsert with text (translated)
        $trUpCols = [$trHashCol, $trLocCol, $trTextCol];
        $trUpVals = [':hash', ':locale', ':text'];

        if ($trCreatedCol) { $trUpCols[] = $trCreatedCol; $trUpVals[] = $nowExpr; }
        if ($trUpdatedCol) { $trUpCols[] = $trUpdatedCol; $trUpVals[] = $nowExpr; }
        if ($trStatusCol)  { $trUpCols[] = $trStatusCol;  $trUpVals[] = "'translated'"; }

        $trUpInsert = "INSERT INTO {$trTable} (" . implode(', ', $trUpCols) . ") VALUES (" . implode(', ', $trUpVals) . ")";

        if ($platform instanceof PostgreSQLPlatform) {
            $sets = [$trTextCol . ' = EXCLUDED.' . $trTextCol];
            if ($trUpdatedCol) { $sets[] = $trUpdatedCol . ' = ' . $nowExpr; }
            if ($trStatusCol)  { $sets[] = $trStatusCol . " = 'translated'"; }
            $sqlTrUpsert = $trUpInsert . " ON CONFLICT ({$trHashCol}, {$trLocCol}) DO UPDATE SET " . implode(', ', $sets);
        } elseif ($platform instanceof SqlitePlatform) {
            $sets = [$trTextCol . ' = excluded.' . $trTextCol];
            if ($trUpdatedCol) { $sets[] = $trUpdatedCol . ' = ' . $nowExpr; }
            if ($trStatusCol)  { $sets[] = $trStatusCol . " = 'translated'"; }
            $sqlTrUpsert = $trUpInsert . " ON CONFLICT({$trHashCol}, {$trLocCol}) DO UPDATE SET " . implode(', ', $sets);
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            $sets = [$trTextCol . ' = VALUES(' . $trTextCol . ')'];
            if ($trUpdatedCol) { $sets[] = $trUpdatedCol . ' = ' . $nowExpr; }
            if ($trStatusCol)  { $sets[] = $trStatusCol . " = 'translated'"; }
            $sqlTrUpsert = $trUpInsert . " ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
        } else {
            $this->logger->error('Unsupported DB platform for TR upsert', ['platform' => $platform::class]);
            return;
        }

        $locales = $this->enabledLocales !== [] ? $this->enabledLocales : [$this->defaultLocale];
        if ($locales === []) {
            $this->logger->warning('No locales resolved; will only upsert STR, no STR_TR rows.');
        }

        $startedTx = false;
        try {
            if (!$conn->isTransactionActive()) {
                $conn->beginTransaction();
                $startedTx = true;
            }

            // Upsert STR + ensure TR rows exist for all locales
            foreach ($this->pending as $hash => $row) {
                $params = ['hash' => $hash, 'original' => $row['original'], 'src' => $row['src']];
                $affected = $conn->executeStatement($sqlStr, $params);
                $this->logger->debug('STR upsert', ['hash' => $hash, 'affected' => $affected]);

                foreach ($locales as $loc) {
                    $p = ['hash' => $hash, 'locale' => (string) $loc];
                    $a = $conn->executeStatement($sqlTrEnsure, $p);
                    $this->logger->debug('TR ensure', ['hash' => $hash, 'locale' => $loc, 'affected' => $a]);
                }
            }

            // Upsert any immediate texts provided by the entity
            foreach ($this->pendingWithText as $row) {
                $a = $conn->executeStatement($sqlTrUpsert, $row);
                $this->logger->debug('TR upsert text', [
                    'hash' => $row['hash'], 'locale' => $row['locale'], 'affected' => $a
                ]);
            }

            if ($startedTx) {
                $conn->commit();
            }
            $this->logger->info('Babel postFlush finished successfully');
        } catch (\Throwable $e) {
            if ($startedTx && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('Babel postFlush failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'sql_str'   => $sqlStr ?? null,
                'sql_tr_ensure' => $sqlTrEnsure ?? null,
                'sql_tr_upsert' => $sqlTrUpsert ?? null,
            ]);
        } finally {
            $this->pending = [];
            $this->pendingWithText = [];
        }
    }

    /** Collect changed translatable values from the UoW */
    private function collectTranslatables(UnitOfWork $uow): void
    {
        $entities = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        );

        $seenEntities = 0;
        foreach ($entities as $entity) {
            $seenEntities++;
            $rc = new ReflectionClass($entity);

            foreach ($rc->getProperties() as $rp) {
                $attrs = $rp->getAttributes(Translatable::class);
                if (!$attrs) {
                    continue;
                }

                $rp->setAccessible(true);
                $original = $rp->getValue($entity);

                if (!\is_string($original) || $original === '') {
                    continue; // nothing to index
                }

                $hash = sha1($original);

                $src = null;
                if (property_exists($entity, 'srcLocale')) {
                    $src = \is_string($entity->srcLocale) && $entity->srcLocale !== '' ? $entity->srcLocale : null;
                }

                $this->pending[$hash] = [
                    'original' => $original,
                    'src'      => $src ?? $this->defaultLocale,
                ];
            }

            if (property_exists($entity, '_pendingTranslations') && \is_array($entity->_pendingTranslations ?? null)) {
                foreach ($entity->_pendingTranslations as $field => $pairs) {
                    if (!\is_array($pairs)) continue;
                    foreach ($pairs as $loc => $txt) {
                        if (!\is_string($loc) || !\is_string($txt) || $txt === '') continue;
                        $backingProp = $field . '_backing';
                        if (!property_exists($entity, $backingProp)) continue;
                        $val = $entity->$backingProp ?? null;
                        if (!\is_string($val) || $val === '') continue;
                        $this->pendingWithText[] = [
                            'hash'   => sha1($val),
                            'locale' => $loc,
                            'text'   => $txt,
                        ];
                    }
                }
            }
        }

        $this->logger->debug('Babel onFlush scanned entities', ['count' => $seenEntities]);
    }
}
