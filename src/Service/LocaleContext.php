<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;
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
 *   The write-side hashing should use class-level BabelLocale/accessor OR getDefault()
 *   (NOT the per-request get()). The per-request get() is display locale only.
 */
final class LocaleContext implements ResetInterface
{
    private ?string $current = null; // lazy-resolved; display/effective locale

    /**
     * @param string   $default  e.g. 'en'
     * @param string[] $enabled  allowed locales, may be []
     */
    public function __construct(
        private readonly ?LocaleAwareInterface $translator = null,
        private readonly ?LocaleSwitcher $switcher = null,
        private readonly ?RequestStack $requests = null,
        #[Autowire(param: 'kernel.default_locale')] private readonly string $default = 'en',
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabled = [],
        private readonly ?LoggerInterface $logger = null,
        #[Autowire(param: 'kernel.debug')] private readonly bool $debug = false,
    ) {}

    /**
     * Current/effective (display) locale.
     *
     * For HTTP, this is lazily derived from RequestStack (and Symfony's LocaleListener already
     * sets Request::getLocale()).
     *
     * Important: get() does NOT attempt to "force" Symfony's translator locale; it only
     * resolves Babel's display locale. Use set()/run() to explicitly override.
     */
    public function get(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $resolved = $this->resolveFromRequest() ?? $this->getDefault();
        $resolved = self::norm($resolved);

        // If enabled_locales is configured, enforce membership.
        // (If enabled list is empty, accept any normalized locale.)
        $enabled = $this->getEnabled();
        if ($enabled !== [] && !\in_array($resolved, $enabled, true)) {
            $this->debugLog('LocaleContext.get: resolved locale not enabled; falling back to default', [
                'resolved' => $resolved,
                'default'  => $this->getDefault(),
                'enabled'  => $enabled,
            ]);
            $resolved = $this->getDefault();
        }

        $this->current = $resolved;

        $this->debugLog('LocaleContext.get: resolved', [
            'current' => $this->current,
            'mode'    => $this->requests?->getCurrentRequest() ? 'http' : 'cli',
        ]);

        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }


    /**
     * Force/override for this run (CLI, explicit app rule).
     * Null resets to default.
     *
     * This DOES apply to \Locale default + LocaleSwitcher/translator, because it's an explicit override.
     */
    public function set(?string $locale = null): void
    {
        $loc = $locale ? self::norm($locale) : $this->getDefault();
        $this->assertAllowed($loc);
        $this->apply($loc, 'override');
    }

    /**
     * Run a callback with a temporary locale, restoring previous afterwards.
     */
    public function run(?string $locale, callable $fn): mixed
    {
        $prev = $this->current;
        $this->set($locale);

        try {
            return $fn();
        } finally {
            // restore previous if known, else restore to default
            $this->apply($prev ?? $this->getDefault(), 'restore');
        }
    }

    public function getDefault(): string
    {
        return self::norm($this->default);
    }

    /** @return string[] allowed locales (may be empty => any allowed) */
    public function getEnabled(): array
    {
        // normalize + unique, preserving stable ordering as best as possible
        $list = array_map(self::norm(...), $this->enabled);
        $list = array_values(array_filter($list, static fn(string $l) => $l !== ''));
        $list = array_values(array_unique($list));
        return $list;
    }

    // ---------- internals ----------

    private function apply(string $locale, string $reason): void
    {
        $locale = self::norm($locale);
        $this->current = $locale;

        // Explicit override: apply globally for this process and translator/switcher.
        \Locale::setDefault($locale);

        if ($this->switcher) {
            $this->switcher->setLocale($locale);
        } elseif ($this->translator) {
            $this->translator->setLocale($locale);
        }

        $this->debugLog('LocaleContext.apply', [
            'locale'  => $locale,
            'reason'  => $reason,
            'enabled' => $this->getEnabled(),
        ]);
    }

    private function assertAllowed(string $locale): void
    {
        $enabled = $this->getEnabled();
        if ($enabled !== [] && !\in_array($locale, $enabled, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported locale "%s". Allowed: %s',
                $locale,
                implode(', ', $enabled)
            ));
        }
    }

    /**
     * Resolve locale from current Request (HTTP).
     *
     * We deliberately do NOT treat query parameter "_locale" as a general override
     * (it is too easy to misuse for APIs and can create cache confusion).
     *
     * Priority:
     *  1) Route attribute "_locale" (only when routing explicitly set it)
     *  2) EasyAdmin query param "ea_locale" (scoped convention)
     *  3) Path prefix "/fr/..." (best-effort)
     *  4) Request::getLocale() (set by Symfony's LocaleListener)
     *  5) Accept-Language best-fit (enabled locales)
     */
    private function resolveFromRequest(): ?string
    {
        $r = $this->requests?->getCurrentRequest();
        if (!$r) {
            return null;
        }

        $candidates = [];

        // 1) Route attribute
        if ($r->attributes->has('_locale')) {
            $candidates[] = (string) $r->attributes->get('_locale');
        }

        // 2) EasyAdmin query param
        $q = $r->query;
        if ($q->has('ea_locale')) {
            $candidates[] = (string) $q->get('ea_locale');
        }

        // 3) Path prefix fallback: /fr/... or /pt-BR/...
        if (\preg_match('#^/([a-z]{2}(?:-[A-Z]{2})?)(/|$)#', $r->getPathInfo(), $m)) {
            $candidates[] = (string) $m[1];
        }

        // 4) Resolved request locale (LocaleListener)
        $candidates[] = (string) $r->getLocale();

        // 5) Accept-Language best fit (enabled locales)
        if ($al = $this->bestFromAcceptLanguage($r)) {
            $candidates[] = $al;
        }

        $enabled = $this->getEnabled();

        foreach ($candidates as $candRaw) {
            $cand = self::norm((string) $candRaw);
            if ($cand === '') {
                continue;
            }
            if ($enabled === [] || \in_array($cand, $enabled, true)) {
                $this->debugLog('LocaleContext.resolveFromRequest: selected', [
                    'selected' => $cand,
                    'enabled'  => $enabled,
                    'uri'      => $r->getRequestUri(),
                    'route'    => $r->attributes->get('_route'),
                    'method'   => $r->getMethod(),
                ]);
                return $cand;
            }
        }

        $this->debugLog('LocaleContext.resolveFromRequest: no candidate matched enabled locales', [
            'candidates' => array_map(static fn($v) => self::norm((string) $v), $candidates),
            'enabled'    => $enabled,
            'uri'        => $r->getRequestUri(),
            'route'      => $r->attributes->get('_route'),
        ]);

        return null;
    }

    private function bestFromAcceptLanguage(Request $r): ?string
    {
        $enabled = $this->getEnabled();

        foreach ($r->getLanguages() as $lang) {
            $lang = self::norm((string) $lang);
            if ($lang === '') {
                continue;
            }
            if ($enabled === [] || \in_array($lang, $enabled, true)) {
                return $lang;
            }
        }

        return null;
    }

    private function debugLog(string $message, array $context = []): void
    {
        if (!$this->debug || !$this->logger) {
            return;
        }
        $this->logger->debug($message, $context);
    }

    private static function norm(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));

        // normalize common "xx" or "xx-YY" forms
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-' . \strtoupper($m[2]) : '';
            return $lang . $reg;
        }

        return $locale;
    }
}
