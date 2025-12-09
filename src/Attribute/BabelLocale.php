<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

use Attribute;

/**
 * Marks which property or method provides the *source* locale
 * for string-backed translations. You can also carry a format hint.
 *
 * Usage:
 *   #[BabelLocale] public ?string $srcLocale = null;
 *   #[BabelLocale] public function getSourceLocale(): ?string { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final class BabelLocale
{
    public function __construct(
        public string $format = 'iso-8859-1',
        public ?string $locale = null, // if set on the class level, always get it from there.
        public ?array $targetLocales = null, // if null, use enabled locales.  Can also be [] meaning no translations
    ) {}
}
