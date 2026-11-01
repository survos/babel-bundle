<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Tests\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\BabelBundle\EventListener\BabelPostLoadHydrator;
use Survos\BabelBundle\EventListener\StringBackedTranslatableFlushSubscriber;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Tests\App\Entity\SimpleOwner;
use Survos\Lingua\Core\Identity\HashUtil;

require_once dirname(__DIR__).'/App/Entity/SimpleOwner.php';

final class DuckDbTranslationTest extends TestCase
{
    public static function engines(): iterable
    {
        yield 'SQLite baseline' => ['sqlite'];
        yield 'DuckDB' => ['duckdb'];
    }

    private function connection(string $engine): Connection
    {
        if ('duckdb' === $engine && (!in_array('duckdb', \PDO::getAvailableDrivers(), true) || !class_exists(\DuckDb\DBAL\Driver::class))) {
            self::markTestSkipped('Install ext-pdo_duckdb and the optional DuckDB DBAL driver.');
        }
        $db = DriverManager::getConnection('duckdb' === $engine
            ? ['driverClass' => \DuckDb\DBAL\Driver::class, 'memory' => true]
            : ['driver' => 'pdo_sqlite', 'memory' => true]);
        if ('duckdb' === $engine) {
            $db->executeStatement('CREATE SEQUENCE str_id_seq');
            $db->executeStatement('CREATE SEQUENCE str_tr_id_seq');
        }
        $strId = 'duckdb' === $engine ? "INTEGER DEFAULT nextval('str_id_seq') PRIMARY KEY" : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $trId = 'duckdb' === $engine ? "INTEGER DEFAULT nextval('str_tr_id_seq') PRIMARY KEY" : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->executeStatement('CREATE TABLE str (id '.$strId.', code VARCHAR UNIQUE, source_locale VARCHAR, source VARCHAR, context VARCHAR, meta VARCHAR)');
        $db->executeStatement('CREATE TABLE str_tr (id '.$trId.', str_code VARCHAR, target_locale VARCHAR, engine VARCHAR, text VARCHAR, meta VARCHAR, UNIQUE(str_code, target_locale, engine))');

        return $db;
    }

    #[DataProvider('engines')]
    public function testTranslationUpsertsAreIdempotent(string $engine): void
    {
        $db = $this->connection($engine);
        try {
            $reflection = new \ReflectionClass(StringBackedTranslatableFlushSubscriber::class);
            $listener = $reflection->newInstanceWithoutConstructor();
            $sql = static fn (string $method, string $table): string => $reflection->getMethod($method)->invoke($listener, $db->getDatabasePlatform(), $table);
            $source = ['code' => 'probe', 'source_locale' => 'nl', 'source' => 'munt', 'context' => null, 'meta' => '{}'];
            $translation = ['str_code' => 'probe', 'target_locale' => 'en', 'engine' => 'babel', 'meta' => '{}'];
            foreach (['coin', 'updated coin'] as $text) {
                $db->executeStatement($sql('buildSqlStrUpsert', 'str'), $source);
                $db->executeStatement($sql('buildSqlTrEnsure', 'str_tr'), $translation);
                $db->executeStatement($sql('buildSqlTrUpsert', 'str_tr'), [...$translation, 'text' => $text]);
            }
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM str'));
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM str_tr'));
            self::assertSame('updated coin', $db->fetchOne('SELECT text FROM str_tr'));
        } finally {
            $db->close();
        }
    }

    #[DataProvider('engines')]
    public function testBlankProviderStubCannotMaskSuppliedEnglish(string $engine): void
    {
        $db = $this->connection($engine);
        try {
            $code = HashUtil::calcSourceKey('munt', 'nl');
            $db->insert('str_tr', ['str_code' => $code, 'target_locale' => 'en', 'engine' => null, 'text' => 'coin', 'meta' => '{}']);
            $db->insert('str_tr', ['str_code' => $code, 'target_locale' => 'en', 'engine' => 'babel', 'text' => null, 'meta' => '{}']);
            $owner = new SimpleOwner();
            $owner->labelBacking = 'munt';
            $index = new TranslatableIndex([SimpleOwner::class => ['fields' => ['label' => ['context' => null]], 'sourceLocale' => 'nl']]);
            $hydrator = new BabelPostLoadHydrator($index, new LocaleContext(default: 'en'), new NullLogger());
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($db);
            $hydrator->postLoad(new PostLoadEventArgs($owner, $em));
            self::assertSame('coin', $owner->getResolvedTranslation('label'));
        } finally {
            $db->close();
        }
    }
}
