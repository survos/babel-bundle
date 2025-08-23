<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Util;

/**
 * Single source of truth for string-back hash calculation.
 * Default: xxh3 over "src|context|original".
 */
final class BabelHasher
{
    public static function forString(?string $srcLocale, ?string $context, string $original): string
    {
        // normalize nulls to empty strings; stable separators
        $src = $srcLocale ?? '';
        $ctx = $context ?? '';
        return hash('xxh3', $src . '|' . $ctx . '|' . $original);
    }
}
