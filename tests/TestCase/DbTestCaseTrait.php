<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\TestCase;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait DbTestCaseTrait
{
    private ?EntityManagerInterface $em = null;

    protected function em(): EntityManagerInterface
    {
        if ($this->em) {
            return $this->em;
        }

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        return $this->em = $em;
    }

    /**
     * Backwards-compatible entry point used by existing tests.
     */
    protected function createSchema(): void
    {
        $this->resetDatabaseSchema();
    }

    protected function resetDatabaseSchema(): void
    {
        $em = $this->em();
        $tool = new SchemaTool($em);

        $classes = $em->getMetadataFactory()->getAllMetadata();
        if ($classes !== []) {
            $tool->dropSchema($classes);
            $tool->createSchema($classes);
        }

        $this->ensureBabelTablesExist();
    }

    private function ensureBabelTablesExist(): void
    {
        $conn = $this->em()->getConnection();

        // STR table: hash must be UNIQUE/PK for ON CONFLICT(hash) upserts.
        $conn->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS str (
  hash        VARCHAR(255) PRIMARY KEY,
  original    TEXT NOT NULL,
  src_locale  VARCHAR(16) NOT NULL,
  created_at  DATETIME NULL,
  updated_at  DATETIME NULL
)
SQL);

        // STR_TRANSLATION: hash must be UNIQUE/PK for ON CONFLICT(hash) upserts.
        $conn->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS str_translation (
  hash        VARCHAR(255) PRIMARY KEY,
  str_hash    VARCHAR(255) NOT NULL,
  locale      VARCHAR(16) NOT NULL,
  text        TEXT NULL,
  created_at  DATETIME NULL,
  updated_at  DATETIME NULL
)
SQL);

        // Helpful for hydrator lookups.
        $conn->executeStatement('CREATE INDEX IF NOT EXISTS idx_str_translation_lookup ON str_translation (str_hash, locale)');
    }
}
