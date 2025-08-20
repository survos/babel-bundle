<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

trait TranslatableHooksTrait
{
    /** @var array<string,string>|null field => code/hash (persisted if you map it) */
    public ?array $tCodes = null;

    /** @var array<string,string> runtime resolved translations (field => text) */
    private array $_resolved = [];

    /**
     * Unified resolver used by your property hooks/getters.
     *
     * Priority:
     *   1) Already-resolved text (filled by TranslatableListener::postLoad via setResolvedTranslation)
     *   2) Optional lazy lookup by tCodes[$field] if the entity provides translateHash($hash)
     *   3) Backing/original value
     */
    protected function resolveTranslatable(string $field, ?string $backing, ?string $context = null): ?string
    {
        // 1) Runtime-resolved (listener populates this during postLoad)
        if (($this->_resolved[$field] ?? '') !== '') {
            return $this->_resolved[$field];
        }

        // 2) Optional lazy resolution using a hash/code if the entity exposes translateHash()
        //    This keeps the trait framework-agnostic; your entity can delegate to a service if desired.
        $hash = $this->tCodes[$field] ?? null;
        if (is_string($hash) && $hash !== '' && \method_exists($this, 'translateHash')) {
            /** @var callable(string):(?string) $cb */
            $cb = [$this, 'translateHash'];
            try {
                $translated = $cb($hash);
                if (is_string($translated) && $translated !== '') {
                    // cache for the remainder of the request lifecycle
                    $this->setResolvedTranslation($field, $translated);
                    return $translated;
                }
            } catch (\Throwable) {
                // swallow and fall through to backing
            }
        }

        // 3) Fallback to backing/original
        return $backing;
    }

    public function setResolvedTranslation(string $field, string $text): void
    {
        $this->_resolved[$field] = $text;
    }

    public function getResolvedTranslation(string $field): ?string
    {
        return $this->_resolved[$field] ?? null;
    }

    /** Safe accessor for private/protected backings like $titleBacking */
    public function getBackingValue(string $field): ?string
    {
        $prop = $field . 'Backing';
        return \property_exists($this, $prop) ? $this->{$prop} : null;
    }
}
