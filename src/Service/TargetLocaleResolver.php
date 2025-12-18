<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

final class TargetLocaleResolver
{
    /**
     * @param list<string> $enabledLocales
     * @param list<string>|null $explicitTargets
     * @return list<string>
     */
    public function resolve(array $enabledLocales, ?array $explicitTargets, string $sourceLocale): array
    {
        $targets = $explicitTargets ?? $enabledLocales;

        // Normalize + preserve order while removing source locale + duplicates.
        $out = [];
        foreach ($targets as $locale) {
            $locale = (string) $locale;
            if ($locale === '' || $locale === $sourceLocale) {
                continue;
            }
            if (!\in_array($locale, $out, true)) {
                $out[] = $locale;
            }
        }

        return $out;
    }
}
