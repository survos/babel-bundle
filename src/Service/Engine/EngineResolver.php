<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

use LogicException;
use Survos\BabelBundle\Service\StringStorageRouter;

/**
 * Resolve the correct storage engine from the compiled router (by entity class),
 * and expose a simple iterateMissing() API for commands.
 */
final class EngineResolver
{
    public function __construct(private readonly StringStorageRouter $router) {}

    /**
     * @param ?string $entityFqcn  e.g. App\Entity\Glam (or null for default)
     * @param string  $locale      target locale
     * @param ?string $source      optional source-locale filter
     * @param int     $limit       optional limit per engine
     * @return iterable<object>    an iterable of Str-like rows (engine-dependent)
     */
    public function iterateMissing(?string $entityFqcn, string $locale, ?string $source, int $limit = 0): iterable
    {
        $engine = $this->resolveEngine($entityFqcn);
        if (!\method_exists($engine, 'iterateMissing')) {
            throw new LogicException('Resolved engine does not support iterateMissing().');
        }
        /** @var iterable<object> $it */
        $it = $engine->iterateMissing($locale, $source, $limit);
        return $it;
    }

    /**
     * Resolve engine from router using common method names; throws if not found.
     */
    public function resolveEngine(?string $entityFqcn): object
    {
        $r = $this->router;

        // Try a set of common router APIs
        foreach (['forClass','resolve','engineFor','get'] as $method) {
            if (\method_exists($r, $method)) {
                /** @var object|null $e */
                $e = $r->$method($entityFqcn);
                if ($e) { return $e; }
            }
        }

        // Default fallback on router
        foreach (['default','getDefault','fallback'] as $method) {
            if (\method_exists($r, $method)) {
                /** @var object $e */
                $e = $r->$method();
                return $e;
            }
        }

        throw new LogicException('No storage engine available from StringStorageRouter.');
    }
}
