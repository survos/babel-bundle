<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Single source of truth for locale decisions.
 *
 * - get():        current/effective locale (lazy from RequestStack)
 * - getDefault(): app default (e.g. 'en')
 * - getEnabled(): allowed locales (may be empty => any)
 * - set():        override for this run (CLI, explicit)
 *
 * NOTE (Babel hashing):
 *   The write-side hashing should use BabelLocale accessor OR getDefault()
 *   (NOT the per-request get()). Callers (subscriber/hydrator) should prefer:
 *     src = accessor || getDefault()
 */
final class LocaleContext
{
    private ?string $current = null; // lazy-resolved

    /**
     * @param string   $default  e.g. 'en'
     * @param string[] $enabled  list of allowed locales, may be []
     */
    public function __construct(
        private readonly ?LocaleAwareInterface $translator = null,
        private readonly ?LocaleSwitcher $switcher = null,
        private readonly ?RequestStack $requests = null,
        #[Autowire(param: 'kernel.default_locale')] private readonly string $default = 'en',
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabled = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Current/effective locale (lazy from RequestStack on first use) */
    public function get(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }
        $resolved = $this->resolveFromRequest() ?? $this->default;
        $this->apply($resolved, 'lazy');
        return $this->current;
    }

    /** Force/override (CLI or explicit app rule). Null resets to default. */
    public function set(?string $locale = null): void
    {
        $loc = $locale ? self::norm($locale) : $this->default;
        $this->assertAllowed($loc);
        $this->apply($loc, 'override');
    }

    /** Run a callback with a temporary locale, restoring previous afterwards. */
    public function run(?string $locale, callable $fn): mixed
    {
        $prev = $this->current;
        $this->set($locale);
        return $fn();
        try { return $fn(); }
        finally {
            $this->apply($prev ?? $this->default, 'restore');
        }
    }

    /** App default (configuration) */
    public function getDefault(): string
    {
        return self::norm($this->default);
    }

    /** @return string[] allowed locales (may be empty => any allowed) */
    public function getEnabled(): array
    {
        $list = array_values(array_unique(array_map(self::norm(...), $this->enabled)));
        return $list;
    }

    // ---------- internals ----------

    private function apply(string $locale, string $reason): void
    {
        $this->current = $locale;
        \Locale::setDefault($locale);
        if ($this->switcher) {
            $this->switcher->setLocale($locale);
        } elseif ($this->translator) {
            $this->translator->setLocale($locale);
        }
        $this->logger?->debug('LocaleContext.apply', [
            'locale' => $locale,
            'reason' => $reason,
        ]);
    }

    private function assertAllowed(string $locale): void
    {
        $enabled = $this->getEnabled();
        if ($enabled && !\in_array($locale, $enabled, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported locale "%s". Allowed: %s',
                $locale,
                implode(', ', $enabled)
            ));
        }
    }

    private function resolveFromRequest(): ?string
    {
        $r = $this->requests?->getCurrentRequest();
        if (!$r) return null;

        // Priority:
        // 1) Route attribute
        $candidates = [];
        if ($r->attributes->has('_locale')) {
            $candidates[] = (string)$r->attributes->get('_locale');
        }

        // 2) Query params (EasyAdmin & general)
        $q = $r->query;
        if ($q->has('_locale'))   $candidates[] = (string)$q->get('_locale');
        if ($q->has('ea_locale')) $candidates[] = (string)$q->get('ea_locale');

        // 3) Path prefix fallback: /fr/... or /pt-BR/...
        if (preg_match('#^/([a-z]{2}(?:-[A-Z]{2})?)(/|$)#', $r->getPathInfo(), $m)) {
            $candidates[] = $m[1];
        }

        // 4) Request's own locale
        $candidates[] = $r->getLocale();

        // 5) Accept-Language best fit (enabled locales)
        if ($al = $this->bestFromAcceptLanguage($r)) {
            $candidates[] = $al;
        }

        foreach ($candidates as $cand) {
            $cand = self::norm((string)$cand);
            if (!$cand) continue;
            $enabled = $this->getEnabled();
            if (!$enabled || \in_array($cand, $enabled, true)) {
                return $cand;
            }
        }
        return null;
    }

    private function bestFromAcceptLanguage(Request $r): ?string
    {
        $enabled = $this->getEnabled();
        foreach ($r->getLanguages() as $lang) {
            $lang = self::norm($lang);
            if (!$enabled || \in_array($lang, $enabled, true)) {
                return $lang;
            }
        }
        return null;
    }

    private static function norm(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $locale;
    }
}
