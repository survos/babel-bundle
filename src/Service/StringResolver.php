<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Str;

/**
 * Resolve string codes -> localized text with a per-request cache
 * and fallback to original when a locale entry is missing.
 */
final class StringResolver
{
    /** @var array<string, array<string,string>> code => [locale => text] */
    private array $cache = [];

    public function __construct(private ManagerRegistry $registry) {}

    /**
     * @param list<string> $codes
     * @return array<string,string> code => text
     */
    public function resolve(array $codes, string $locale, ?string $emName = null): array
    {
        // normalize + dedupe
        $codes = array_values(array_unique(array_filter($codes, 'strlen')));
        if (!$codes) { return []; }

        // find codes not cached for this locale
        $missing = [];
        foreach ($codes as $c) {
            if (!isset($this->cache[$c][$locale])) {
                $missing[] = $c;
            }
        }

        if ($missing) {
            $em   = $this->registry->getManager($emName);
            $repo = $em->getRepository(Str::class);

            /** @var iterable<Str> $rows */
            $rows = $repo->findBy(['code' => $missing]);
            foreach ($rows as $s) {
                $t = $s->t; // denormalized map: locale => text
                $this->cache[$s->code][$locale] = $t[$locale] ?? $s->original;
            }

            // ensure unknown codes don't trigger notices later
            foreach ($missing as $c) {
                $this->cache[$c][$locale] ??= '';
            }
        }

        // build output in original order
        $out = [];
        foreach ($codes as $c) {
            $out[$c] = $this->cache[$c][$locale] ?? '';
        }
        return $out;
    }
}
