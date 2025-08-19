<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Runtime;

use Survos\BabelBundle\Service\TranslationStore;

final class BabelRuntime
{
    private static ?TranslationStore $store = null;
    private static ?string $locale = null;
    private static string $fallback = 'en';

    public static function init(TranslationStore $store, ?string $locale, string $fallback = 'en'): void
    {
        self::$store = $store;
        self::$locale = $locale;
        self::$fallback = $fallback;
    }

    public static function setLocale(?string $locale): void { self::$locale = $locale; }
    public static function getLocale(): ?string { return self::$locale; }

    public static function getStore(): TranslationStore
    {
        if (!self::$store) {
            throw new \LogicException('BabelRuntime not initialized: TranslationStore missing.');
        }
        return self::$store;
    }

    public static function fallback(): string { return self::$fallback; }
}
