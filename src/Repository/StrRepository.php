<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use App\Entity\Str;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Str|null find($id, $lockMode = null, $lockVersion = null)
 * @method Str|null findOneBy(array $criteria, array $orderBy = null)
 * @method Str[]    findAll()
 * @method Str[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Str::class);
    }

    /**
     * Batch upsert for (code, original, srcLocale).
     * $items = [ [code, original, srcLocale], ... ]
     *
     * Uses platform-appropriate UPSERT:
     * - PostgreSQL: ON CONFLICT (code) DO UPDATE ...
     * - SQLite:     ON CONFLICT(code) DO UPDATE ...
     * - MySQL:      ON DUPLICATE KEY UPDATE ...
     *
     * Returns number of processed rows.
     */
    public function upsertMany(array $items): int
    {
        if (!$items) {
            return 0;
        }

        $conn = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform()->getName(); // 'sqlite', 'postgresql', 'mysql'

        [$sql, $paramsPerRow] = $this->buildUpsertSql($platform);

        $affected = 0;
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare($sql);
            foreach ($items as [$code, $original, $srcLocale]) {
                $params = \array_combine($paramsPerRow, [$code, $original, $srcLocale]);
                dd($params);
                $stmt->executeStatement($params);
                $affected++;
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        return $affected;
    }

    /**
     * Build platform-specific UPSERT for table "str" with columns:
     *   code (PK), original, src_locale, updated_at
     * Adjust to your actual column names if they differ.
     */
    private function buildUpsertSql(string $platform): array
    {
        // Named parameters keep it readable
        $params = [':code', ':original', ':src'];

        switch ($platform) {
            case 'postgresql':
                $sql = <<<SQL
INSERT INTO str (code, original, src_locale, updated_at)
VALUES (:code, :original, :src, NOW())
ON CONFLICT (code) DO UPDATE
    SET original = EXCLUDED.original,
        src_locale = EXCLUDED.src_locale,
        updated_at = NOW()
SQL;
                break;

            case 'sqlite':
                $sql = <<<SQL
INSERT INTO str (code, original, src_locale, updated_at)
VALUES (:code, :original, :src, CURRENT_TIMESTAMP)
ON CONFLICT(code) DO UPDATE SET
    original = excluded.original,
    src_locale = excluded.src_locale,
    updated_at = CURRENT_TIMESTAMP
SQL;
                break;

            case 'mysql':
            case 'mariadb':
            default:
                $sql = <<<SQL
INSERT INTO str (code, original, src_locale, updated_at)
VALUES (:code, :original, :src, NOW())
ON DUPLICATE KEY UPDATE
    original = VALUES(original),
    src_locale = VALUES(src_locale),
    updated_at = NOW()
SQL;
                break;
        }

        return [$sql, $params];
    }
}
