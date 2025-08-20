<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class LocaleContext
{
    private string $current;
    private string $default;
    /** @var string[] */
    private array $enabled;

    public function __construct(
        private ?LocaleAwareInterface $translator = null,
        private ?LocaleSwitcher $switcher = null,
        ?ParameterBagInterface $params = null
    ) {
        $this->default = $this->normalize((string) ($params?->get('kernel.default_locale') ?? 'en'));
        $enabled = $params?->get('kernel.enabled_locales') ?? [];
        $this->enabled = \is_array($enabled) ? array_values(array_map([$this, 'normalize'], $enabled)) : [];

        $this->current = $this->default;
        \Locale::setDefault($this->current);
        $this->syncFramework($this->current);
    }

    public function get(): string { return $this->current; }
    public function getDefault(): string { return $this->default; }
    /** @return string[] */
    public function getEnabled(): array { return $this->enabled; }

    /** Set active locale; null => reset to default */
    public function set(?string $locale = null): void
    {
        $locale = $locale === null ? $this->default : $this->normalize($locale);
        $this->assertSupported($locale);
        $this->current = $locale;
        \Locale::setDefault($locale);
        $this->syncFramework($locale);
    }

    /** Temporarily switch locale during callback, restore afterwards */
    public function run(?string $locale, callable $callback): mixed
    {
        $prev = $this->get();
        $this->set($locale);
        try { return $callback(); }
        finally { $this->set($prev); }
    }

    private function normalize(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $locale;
    }

    private function assertSupported(string $locale): void
    {
        if ($this->enabled && !\in_array($locale, $this->enabled, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported locale "%s". Allowed: %s',
                $locale,
                implode(', ', $this->enabled)
            ));
        }
    }

    private function syncFramework(string $locale): void
    {
        if ($this->switcher) {
            $this->switcher->setLocale($locale);
        } elseif ($this->translator) {
            $this->translator->setLocale($locale);
        }
    }
}
