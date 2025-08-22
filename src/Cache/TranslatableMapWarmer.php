<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;

/**
 * Precomputes the class => fields map during cache:warmup.
 */
final class TranslatableMapWarmer implements CacheWarmerInterface
{
    public const CACHE_KEY = 'babel.translatable_map';

    public function __construct(
        private TranslatableScanner $scanner,
        private CacheItemPoolInterface $cachePool, // Symfony cache.app
    ) {}

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $map = $this->scanner->buildMap();
        $item = $this->cachePool->getItem(self::CACHE_KEY);
        $item->set($map);
        // store forever; the map is rebuilt on cache warmup anyway
        $this->cachePool->save($item);
        return [];
    }
}
