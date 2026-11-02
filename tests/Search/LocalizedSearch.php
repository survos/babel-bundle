<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Search;

use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;

#[AsSearch(index: LocalizedOwner::class, name: 'localized_test')]
final class LocalizedSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        $this->setAdapterParameters([
            DoctrineAdapter::SEARCH_FIELDS => ['o.code', 'o.label'],
            DoctrineAdapter::COUNT_DISTINCT => false,
            DoctrineAdapter::FETCH_JOIN_COLLECTION => false,
        ]);
        $this->addFacet('category', 'Material');
    }
}
