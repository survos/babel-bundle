<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Search;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\BabelBundle\EventListener\BabelPostLoadHydrator;
use Survos\BabelBundle\Search\TranslatedFieldPredicate;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Search\Query;
use Symfony\Component\OptionsResolver\OptionsResolver;

require_once __DIR__.'/LocalizedOwner.php';

final class TranslatedSearchTest extends TestCase
{
    public static function engines(): iterable
    {
        yield 'SQLite' => ['sqlite'];
        yield 'DuckDB' => ['duckdb'];
    }

    private function setupSearch(string $engine, string $locale, bool $dynamic = false): array
    {
        if (!class_exists(DoctrineAdapter::class) || !defined(DoctrineAdapter::class.'::SEARCH_FIELD_PREDICATE')) {
            self::markTestSkipped('Install SearchBundle with the optional field-predicate hook.');
        }
        require_once __DIR__.'/LocalizedSearch.php';
        if ('duckdb' === $engine && (!in_array('duckdb', \PDO::getAvailableDrivers(), true) || !class_exists(\DuckDb\DBAL\Driver::class))) {
            self::markTestSkipped('Install ext-pdo_duckdb and the optional DBAL driver.');
        }
        $db = DriverManager::getConnection('duckdb' === $engine
            ? ['driverClass' => \DuckDb\DBAL\Driver::class, 'memory' => true]
            : ['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE localized_owner (code VARCHAR PRIMARY KEY, label_backing VARCHAR, category VARCHAR, source_locale VARCHAR)');
        $db->executeStatement('CREATE TABLE str (id INTEGER PRIMARY KEY, code VARCHAR, source VARCHAR, source_locale VARCHAR, context VARCHAR, meta VARCHAR)');
        $db->executeStatement('CREATE TABLE str_tr (id INTEGER PRIMARY KEY, str_code VARCHAR, target_locale VARCHAR, engine VARCHAR, text VARCHAR, status VARCHAR, updated_at TIMESTAMP, meta VARCHAR)');
        foreach ([['CER-001', 'mineraalwaterfles', 'CER'], ['GLS-002', 'waterfles', 'GLS'], ['MTL-003', 'spijker', 'MTL']] as $i => [$code, $source, $category]) {
            $key = HashUtil::calcSourceKey($source, 'nl');
            $db->insert('localized_owner', ['code' => $code, 'label_backing' => $source, 'category' => $category, 'source_locale' => 'nl']);
            $db->insert('str', ['id' => $i + 1, 'code' => $key, 'source' => $source, 'source_locale' => 'nl']);
            $db->insert('str_tr', ['id' => $i + 1, 'str_code' => $key, 'target_locale' => 'en', 'text' => [0 => 'mineral water bottle', 1 => null, 2 => 'nail'][$i]]);
        }
        // A later, blank provider stub cannot mask supplied English or block source fallback.
        $db->insert('str_tr', ['id' => 10, 'str_code' => HashUtil::calcSourceKey('mineraalwaterfles', 'nl'), 'target_locale' => 'en', 'engine' => 'babel', 'text' => '']);
        $db->insert('str_tr', ['id' => 11, 'str_code' => HashUtil::calcSourceKey('waterfles', 'nl'), 'target_locale' => 'en', 'engine' => 'babel', 'text' => '']);
        // Same source text in a different source language must not contaminate the match.
        $db->insert('str', ['id' => 20, 'code' => HashUtil::calcSourceKey('spijker', 'de'), 'source' => 'spijker', 'source_locale' => 'de']);
        $db->insert('str_tr', ['id' => 20, 'str_code' => HashUtil::calcSourceKey('spijker', 'de'), 'target_locale' => 'en', 'text' => 'bottle']);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__, dirname(__DIR__, 2).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($db, $config);
        $index = new TranslatableIndex([LocalizedOwner::class => [
            'fields' => ['label' => ['context' => null]],
            'sourceLocale' => $dynamic ? null : 'nl',
            'localeAccessor' => $dynamic ? ['type' => 'prop', 'name' => 'sourceLocale', 'format' => null] : null,
        ]]);
        $locales = new LocaleContext(default: 'nl');
        $locales->set($locale);
        $em->getEventManager()->addEventListener([Events::postLoad], new BabelPostLoadHydrator($index, $locales, new NullLogger()));
        $adapter = new DoctrineAdapter($em);
        $search = (new LocalizedSearch())->create();
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $parameters = $search->getAdapterParameters();
        $parameters[DoctrineAdapter::SEARCH_FIELD_PREDICATE] = (new TranslatedFieldPredicate($index, $locales))->predicate(...);
        $search->setResolvedAdapterParameters($resolver->resolve($parameters));

        return [$em, $adapter, $search];
    }

    #[DataProvider('engines')]
    public function testChosenLanguageAndMissingTranslationFallback(string $engine): void
    {
        foreach (['en' => ['bottle' => ['CER-001'], 'fles' => ['GLS-002'], 'BOTTLE' => ['CER-001']], 'nl' => ['fles' => ['CER-001', 'GLS-002'], 'bottle' => []]] as $locale => $cases) {
            [$em, $adapter, $search] = $this->setupSearch($engine, $locale);
            try {
                foreach ($cases as $term => $expected) {
                    $em->clear();
                    $result = $adapter->search((new Query())->setQueryString($term)->setActiveSort('o.code:asc'), $search);
                    self::assertSame($expected, array_map(static fn ($hit) => $hit->getData()->code, $result->getHits()));
                    self::assertSame(count($expected), $result->getTotalResults());
                }
                $em->clear();
                $query = (new Query())->setQueryString('CER-001');
                $result = $adapter->search($query, $search);
                self::assertSame(1, $result->getTotalResults(), 'Untranslated identifiers stay searchable.');
                self::assertSame('en' === $locale ? 'mineral water bottle' : 'mineraalwaterfles', $result->getHits()[0]->getData()->label);
            } finally {
                $em->close();
                $em->getConnection()->close();
            }
        }
    }

    #[DataProvider('engines')]
    public function testEmptyExecutionQueryDoesNotApplyKeywordPredicate(string $engine): void
    {
        [$em, $adapter, $search] = $this->setupSearch($engine, 'en');
        try {
            $parameters = $search->getResolvedAdapterParameters();
            $parameters[DoctrineAdapter::SEARCH_FIELD_PREDICATE] = static function (): never {
                throw new \LogicException('An empty/semantic execution query must bypass keyword matching.');
            };
            $search->setResolvedAdapterParameters($parameters);
            self::assertSame(3, $adapter->search(new Query(), $search)->getTotalResults());
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
    }

    #[DataProvider('engines')]
    public function testMappedSourceLocaleFallsBackToDefaultWhenMissing(string $engine): void
    {
        foreach (['en' => 'bottle', 'nl' => 'fles'] as $locale => $term) {
            [$em, $adapter, $search] = $this->setupSearch($engine, $locale, true);
            try {
                $em->getConnection()->executeStatement('UPDATE localized_owner SET source_locale = NULL WHERE code = ?', ['CER-001']);
                $result = $adapter->search((new Query())->setQueryString($term), $search);
                self::assertContains('CER-001', array_map(static fn ($hit) => $hit->getData()->code, $result->getHits()));
            } finally {
                $em->close();
                $em->getConnection()->close();
            }
        }
    }

    #[DataProvider('engines')]
    public function testFacetsPaginationAndDeterministicNonblankProvider(string $engine): void
    {
        [$em, $adapter, $search] = $this->setupSearch($engine, 'en', true);
        try {
            $query = (new Query())->setQueryString('bottle')->addActiveFilter(new TermFilter('category', ['GLS']));
            $result = $adapter->search($query, $search);
            self::assertSame(0, $result->getTotalResults());
            self::assertSame(['CER' => 1], $result->getFacetDistribution('category')->getValues(), 'Facet counts use the same translated predicate and exclude their own filter.');
            $em->getConnection()->insert('str_tr', ['id' => 30, 'str_code' => HashUtil::calcSourceKey('mineraalwaterfles', 'nl'), 'target_locale' => 'en', 'engine' => 'reviewed', 'text' => 'water flask']);
            $result = $adapter->search((new Query())->setQueryString('bottle'), $search);
            self::assertSame(0, $result->getTotalResults(), 'Superseded provider text must not match.');
            $result = $adapter->search((new Query())->setQueryString('flask'), $search);
            self::assertSame('water flask', $result->getHits()[0]->getData()->label);
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
        [$em, $adapter, $search] = $this->setupSearch($engine, 'nl');
        try {
            $result = $adapter->search((new Query())->setQueryString('fles')->setActiveSort('o.code:asc')->setActiveHitsPerPage(1)->setCurrentPage(2), $search);
            self::assertSame(2, $result->getTotalResults());
            self::assertSame('GLS-002', $result->getHits()[0]->getData()->code);
        } finally {
            $em->close();
            $em->getConnection()->close();
        }
    }
}
