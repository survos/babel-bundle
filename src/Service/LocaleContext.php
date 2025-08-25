<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class LocaleContext
{
    private string $default;
    /** @var string[] */
    private array $enabled;
    private ?string $current = null; // ← lazy until first get()
    public function __construct(
        private ?LocaleAwareInterface $translator = null,
        private ?LocaleSwitcher $switcher = null,
        private ?RequestStack $requests = null,
        ?ParameterBagInterface $params = null,
    ) {
        $this->default = self::norm((string) ($params?->get('kernel.default_locale') ?? 'en'));
        $enabled = $params?->get('kernel.enabled_locales') ?? [];
        $this->enabled = \is_array($enabled) ? array_values(array_map(self::norm(...), $enabled)) : [];
    }

    public function get(): string
    {
        // Lazy resolve once, then cache
        if ($this->current === null) {
            $this->current = $this->resolveFromRequest() ?? $this->default;
            \Locale::setDefault($this->current);
            $this->syncFramework($this->current);
        }
        return $this->current;
    }

    public function getDefault(): string { return $this->default; }
    /** @return string[] */ public function getEnabled(): array { return $this->enabled; }

    /** Force/override for this run (e.g. CLI or query option) */
    public function set(?string $locale = null): void
    {
        $loc = $locale ? self::norm($locale) : $this->default;
        if ($this->enabled && !\in_array($loc, $this->enabled, true)) {
            throw new \InvalidArgumentException("Unsupported locale {$loc}");
        }
        $this->current = $loc;
        \Locale::setDefault($loc);
        $this->syncFramework($loc);
    }

    private function resolveFromRequest(): ?string
    {
        $r = $this->requests?->getCurrentRequest();
        if (!$r) return null;

        // priority: route _locale > request->getLocale() > Accept-Language
        $candidates = [];
        if ($r->attributes->has('_locale')) $candidates[] = (string)$r->attributes->get('_locale');
        $candidates[] = $r->getLocale();
        if ($al = $this->bestFromAcceptLanguage($r)) $candidates[] = $al;

        foreach ($candidates as $cand) {
            $cand = self::norm((string)$cand);
            if ($cand && (!$this->enabled || \in_array($cand, $this->enabled, true))) {
                return $cand;
            }
        }
        return null;
    }

    private function bestFromAcceptLanguage(Request $r): ?string
    {
        $langs = $r->getLanguages(); // already sorted by q
        foreach ($langs as $lang) {
            $lang = self::norm($lang);
            if (!$this->enabled || \in_array($lang, $this->enabled, true)) {
                return $lang;
            }
        }
        return null;
    }

    private function syncFramework(string $locale): void
    {
        if ($this->switcher)       { $this->switcher->setLocale($locale); }
        elseif ($this->translator) { $this->translator->setLocale($locale); }
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
