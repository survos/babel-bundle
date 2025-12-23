<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

/**
 * Translatable metadata index.
 *
 * Map shape:
 * [
 *   FQCN => [
 *     'fields'         => [ fieldName => ['context' => ?string], ... ],
 *     'terms'          => [ fieldName => ['set'=>string,'multiple'=>bool,'context'=>?string], ... ],
 *     'localeAccessor' => ['type'=>'prop'|'method','name'=>string,'format'=>?string] | null,
 *     'sourceLocale'   => ?string,        // from #[BabelLocale(locale: ...)]
 *     'targetLocales'  => ?array<string>, // from #[BabelLocale(targetLocales: [...])]
 *     'hasTCodes'      => bool,
 *   ],
 * ]
 */
final class TranslatableIndex
{
    /**
     * @param array<string, array<string,mixed>> $map
     */
    public function __construct(
        private readonly array $map = [],
    ) {
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function all(): array
    {
        return $this->map;
    }

    public function has(string $class): bool
    {
        return \array_key_exists($class, $this->map);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function configFor(string $class): ?array
    {
        return $this->map[$class] ?? null;
    }

    /**
     * @return list<string>
     */
    public function fieldsFor(string $class): array
    {
        $cfg = $this->map[$class] ?? null;
        if (!$cfg || empty($cfg['fields']) || !\is_array($cfg['fields'])) {
            return [];
        }

        return \array_keys($cfg['fields']);
    }

    /**
     * Term-backed fields (#[BabelTerm]).
     *
     * @return array<string, array{set:string,multiple:bool,context:?string}>
     */
    public function termsFor(string $class): array
    {
        $cfg = $this->map[$class] ?? null;
        $terms = $cfg['terms'] ?? null;

        if (!$terms || !\is_array($terms)) {
            return [];
        }

        /** @var array<string, array{set:string,multiple:bool,context:?string}> $terms */
        return $terms;
    }

    /**
     * @return array{type:string,name:string,format:?string}|null
     */
    public function localeAccessorFor(string $class): ?array
    {
        $cfg = $this->map[$class] ?? null;

        /** @var array{type:string,name:string,format:?string}|null $acc */
        $acc = $cfg['localeAccessor'] ?? null;

        return $acc;
    }

    public function sourceLocaleFor(string $class): ?string
    {
        $cfg = $this->map[$class] ?? null;

        return \is_string($cfg['sourceLocale'] ?? null)
            ? $cfg['sourceLocale']
            : null;
    }

    /**
     * Raw targetLocales from the map (can be:
     *   - null  => use global enabled locales
     *   - []    => explicitly "no translations" for this class
     *   - ['es','fr'] => exactly those locales
     *
     * @return array<string>|null
     */
    public function targetLocalesFor(string $class): ?array
    {
        $cfg = $this->map[$class] ?? null;
        $val = $cfg['targetLocales'] ?? null;

        if ($val === null) {
            return null;
        }

        if (!\is_array($val)) {
            return null;
        }

        // Normalize to strings only.
        return \array_values(\array_map(
            static fn ($v): string => (string) $v,
            $val
        ));
    }

    /**
     * Helper to compute the effective target locales for a class, given a
     * fallback list (typically LocaleContext::getEnabled() or default).
     *
     * - If targetLocalesFor() returns [] we preserve the empty set.
     * - If null, we return the fallback list.
     *
     * @param array<string> $fallbackLocales
     * @return array<string>
     */
    public function effectiveTargetLocalesFor(string $class, array $fallbackLocales): array
    {
        $targets = $this->targetLocalesFor($class);

        if ($targets === null) {
            return $fallbackLocales;
        }

        return $targets;
    }
}
