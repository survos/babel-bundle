<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use App\Entity\StrTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StrTranslation|null find($id, $lockMode = null, $lockVersion = null)
 * @method StrTranslation|null findOneBy(array $criteria, array $orderBy = null)
 * @method StrTranslation[]    findAll()
 * @method StrTranslation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
final class StrTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StrTranslation::class);
    }

    /**
     * Upsert a single translation (code, locale) => text.
     * If $touchStatus = true and "status" column exists, sets 'translated'.
     */
    public function upsertTranslation(string $code, string $locale, string $text, bool $touchStatus = true): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform()->getName();

        [$sql, $params] = $this->buildUpsertSql($platform, $touchStatus);

        $conn->executeStatement($sql, [
            'code'   => $code,
            'locale' => $locale,
            'text'   => $text,
        ]);
    }

    private function buildUpsertSql(string $platform, bool $touchStatus): array
    {
        // Note: if your "status" column doesn't exist, set $touchStatus = false.
        $statusSet = $touchStatus ? ", status = 'translated'" : '';

        switch ($platform) {
            case 'postgresql':
                $sql = <<<SQL
INSERT INTO str_translation (hash, locale, text, updated_at)
VALUES (:code, :locale, :text, NOW())
ON CONFLICT (hash, locale) DO UPDATE
   SET text = EXCLUDED.text,
       updated_at = NOW()
       {$statusSet}
SQL;
                break;

            case 'sqlite':
                $sql = <<<SQL
INSERT INTO str_translation (hash, locale, text, updated_at)
VALUES (:code, :locale, :text, CURRENT_TIMESTAMP)
ON CONFLICT(hash, locale) DO UPDATE SET
    text = excluded.text,
    updated_at = CURRENT_TIMESTAMP
    {$statusSet}
SQL;
                break;

            case 'mysql':
            case 'mariadb':
            default:
                $sql = <<<SQL
INSERT INTO str_translation (hash, locale, text, updated_at)
VALUES (:code, :locale, :text, NOW())
ON DUPLICATE KEY UPDATE
    text = VALUES(text),
    updated_at = NOW()
    {$statusSet}
SQL;
                break;
        }

        return [$sql, []];
    }
}
