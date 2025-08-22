<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Traits;

use Survos\BabelBundle\Attribute\Translatable;

/**
 * Attribute-driven translatable storage for property-backed entities.
 *
 * Your entity must:
 *  - implement getSourceLocale(): ?string
 *  - implement &i18nStorage(): array  (returns reference to the per-locale JSON/blob map)
 *
 * Mark PUBLIC properties with #[Translatable] and use PHP 8.4 hooks to connect them
 * to private backing fields (e.g. $title_raw). Example:
 *
 *   #[Translatable]
 *   public string $title {
 *     get => $this->title_raw;
 *     set => $this->title_raw = $value;
 *   }
 *
 *   private string $title_raw = '';
 *   private ?array $i18n_raw = null;
 *
 *   protected function &i18nStorage(): array { $this->i18n_raw ??= []; return $this->i18n_raw; }
 *   public function getSourceLocale(): ?string { return 'en'; }
 */
trait BabelTranslatableAttrTrait
{
    /**
     * Discover translatable fields by scanning #[Translatable] on PUBLIC properties.
     *
     * @return list<string>
     */
    public function getTranslatableFields(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $fields = [];
        $ref = new \ReflectionObject($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $attrs = $p->getAttributes(Translatable::class);
            if (!$attrs) {
                continue;
            }
            /** @var Translatable $meta */
            $meta = $attrs[0]->newInstance();
            $name = $meta->name ?? $p->getName();
            $fields[] = $name;
        }

        // dedupe and freeze
        $cache = array_values(array_unique($fields));
        return $cache;
    }

    /**
     * Return the text for a given field+locale.
     * For the source locale, read from the public property (which proxies to backing).
     */
    public function getText(string $field, string $locale): ?string
    {
        $src = $this->getSourceLocale() ?? 'en';
        if ($locale === $src) {
            // read via public property hook (throws if property missing)
            return $this->$field ?? null;
        }

        $i18n = $this->i18nStorage();
        return $i18n[$field][$locale] ?? null;
    }

    /**
     * Set the text for a given field+locale into the per-locale blob store.
     */
    public function setText(string $field, string $locale, string $text): void
    {
        $i18n = &$this->i18nStorage();
        $i18n[$field] ??= [];
        $i18n[$field][$locale] = $text;
    }

    /** Returns the app/entity's source locale, e.g. 'en' */
    abstract public function getSourceLocale(): ?string;

    /**
     * Returns a reference to the per-locale storage map.
     * Typically backed by a JSON column, e.g. private ?array $i18n_raw = null;
     */
    abstract protected function &i18nStorage(): array;
}
