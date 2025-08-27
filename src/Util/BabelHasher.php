<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Util;

/**
 * Canonical hash for string-backed translations.
 *
 * Inputs: (srcLocale | context | original)
 * - srcLocale: BabelLocale accessor or DEFAULT locale (NOT current request)
 * - context:   optional domain/context string (nullable)
 * - original:  the source text (backing)
 *
 * Algorithm: xxh3 (fast & stable). Throws if unavailable.
 */
final class BabelHasher
{
    private const ALGO = 'xxh3';

    public static function forString(?string $srcLocale, ?string $context, string $original): string
    {
        if (!\in_array(self::ALGO, \hash_algos(), true)) {
            throw new \RuntimeException(sprintf(
                'Hash algorithm "%s" not available in this PHP build. Available: %s',
                self::ALGO,
                implode(', ', \hash_algos())
            ));
        }

        $src = $srcLocale ?? '';
        $ctx = $context   ?? '';
        // Do NOT trim $original; spaces/newlines are significant for keys
        $material = $src . '|' . $ctx . '|' . $original;

        // Short keys are fine; DB PK is VARCHAR(64) so we keep the hex short
        return \hash(self::ALGO, $material, false);
    }
}
