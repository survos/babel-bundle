<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Survos\BabelBundle\Cache\TranslatableMapWarmer;

final class TranslatableMapProvider
{
    public function __construct(private CacheItemPoolInterface $cachePool) {}

    /** @return array<class-string, list<string>> */
    public function get(): array
    {
        $item = $this->cachePool->getItem(TranslatableMapWarmer::CACHE_KEY);
        return $item->isHit() ? (array)$item->get() : [];
    }
}
