# SurvosBabelBundle

> **A pragmatic, attribute‑driven translation bundle for Symfony 7.3 / PHP 8.4**
>
> • Mark translatable fields with attributes → generate property hooks → persist source strings & translations into normalized `str` / `str_translation` tables.
>
> • Built for **automated translation workflows** (LibreTranslate, DeepL, Google) and **search indexing** (e.g., Meilisearch)—not per‑entity join tables.

---

## What’s new (since the last README)

* **Optional SurvosTranslatorBundle integration.** `babel:translate` can fall back to engines (Libre, DeepL, Google) via a **soft dependency**. `--engine` and `--all` are supported; interactive selection when multiple engines exist.

---

## Why another translation bundle?

**SurvosBabelBundle** focuses on:

* **Source‑of‑truth normalization** — all unique strings live once in `str`; translations in `str_translation` (`hash, locale` composite PK).
* **Attribute → code generation** — use `#[Translatable]` on properties; the **SurvosCodeBundle** command converts them into modern **property hooks** and a `*TranslationsTrait`.
* **Automation‑first** — placeholder rows are created for all `framework.enabled_locales`, perfect for batch translation jobs.
* **Ergonomic reads** — Doctrine `postLoad` resolves text so `entity->title` “just works” in the current locale.
* **Search‑friendly** — simple to emit localized search documents (often one index per language).

> Compared to Gedmo/DoctrineExtensions: fewer joins, cleaner storage, and a DX that embraces PHP 8.4 property hooks.

---

## Requirements

* PHP ^8.4
* Symfony ^7.3
* Doctrine ORM ^3.5.2 (to support Property Hooks)
* Bundles:

    * `survos/babel-bundle` (this bundle)
    * `survos/code-bundle` (hook/trait generator, --dev only)

> SQLite and PostgreSQL are supported. On SQLite we batch/queue writes and drain on `postFlush` to avoid locks. PostgreSQL is recommended in production.

---

## Quickstart

```bash
# 1) New app
symfony new --webapp babel-demo && cd babel-demo

# 2) DB (SQLite to try quickly)
echo 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"' > .env.local

# 3) Install bundles
composer require survos/babel-bundle:dev-main
composer require survos/code-bundle --dev

# 4) Framework locales (example)
# config/packages/translation.yaml
# framework:
#   default_locale: en
#   enabled_locales: ['en','de','es']
#   translator:
#     default_path: '%kernel.project_dir%/translations'
#     fallbacks: ['en']

# 5) Create an entity and mark fields
php bin/console make:entity Post -n
#   Add: title (string, nullable), body (text, nullable)
#   Then annotate fields in src/Entity/Post.php with #[Translatable]

# 6) Generate hooks & trait
bin/console code:translatable:trait

# 7) Update schema
bin/console doctrine:schema:update --force

# 8) Sanity checks
bin/console babel:translatables:dump -vvv
```

### Example entity (before)

```php
use Survos\BabelBundle\Attribute\Translatable;

class Post
{
    #[Translatable]
    public ?string $title = null;

    #[Translatable(context: 'post.body')]
    public ?string $body = null;
}
```

### After running `code:translatable:trait`

* A trait `src/Entity/Translations/PostTranslationsTrait.php` is generated with **property hooks** and a backing property (`$titleBacking`, …).
* Your entity `use`s that trait plus `BabelHooksTrait`, which supplies the hooks API the hydrator expects.
* Continue using `$post->title = 'Hello';` — the backing value is persisted; the write listener computes the **hash**, upserts `str` + source `str_translation`, and provisions placeholders for other locales.

---

## How it works

1. **Mark fields** with `#[Translatable]` (in entities or traits). Optionally mark a source-locale field with an attribute in your app (or rely on `framework.default_locale`).
2. **Generate hooks** with `code:translatable:trait` (SurvosCodeBundle). Fields become property hooks with `<field>Backing` and a `*TranslationsTrait`.
3. **Compiler passes** discover translatable fields across entities/traits and build a runtime **TranslatableIndex**.
4. **On write** (`prePersist`/`preUpdate`): a deterministic **hash** is computed (`xxh3(srcLocale\0context\0text)`), `str` and source `str_translation` are upserted, and placeholders for the remaining `enabled_locales` are created.
5. **On load** (`postLoad`): the hydrator fetches translations via **DBAL** and writes them via the hooks API — `setResolvedTranslation($field, $text)` — so reads are localized.

### Tables

* `str(hash, original, src_locale, context, meta, created_at, updated_at)`
* `str_translation(hash, locale, text, meta, created_at, updated_at[, status])`

> Consider a `status` enum (`UNTRANSLATED|MACHINE|HUMAN|REVIEWED`) in your app’s `StrTranslation` for workflow visibility.

---

## Carriers & storage selection

Mark entities that store translations with `#[BabelStorage]` so the router can select the **property‑mode storage**.

```php
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

#[BabelStorage]
class Article implements TranslatableResolvedInterface
{
    use BabelHooksTrait;
    // … fields with #[Translatable]
}
```

If you see `EngineResolver failed for browse` / `No storage engine available`, you likely missed `#[BabelStorage]`.

---

## Optional: external engines (SurvosTranslatorBundle)

This bundles deals with the storage and retrieval of translated data.  How to translate is up to the application.  However,
we do provide a nice integration with survos/translator-bundle.   Install it when you want machine translation for missing rows:

If you don't already have a translation workflow, we suggest DeepL (500,000 characters/month free) or LibreTranslate (free for 7 days, then a small fee.  completely free if self-hosted)

* Create a DeepL account and get an API key: https://support.deepl.com/hc/en-us/articles/360020695820-API-key-for-DeepL-API
* composer req survos/translator-bundle:dev-main

Add your DEEPL_API_KEY to .env.local or however you set environment variables

```bash
bin/console babel:translate 
```

`babel:translate` behavior:

* Tries your **event‑based** translators first (if you have listeners).
* If no result, falls back to **SurvosTranslatorBundle** engines.
* `--engine <name>` picks a specific engine; if multiple exist and none specified, you’ll be prompted.
* `--all` translates with **all** configured engines and prints a comparison table.
* If you pass `--engine` but the translator bundle isn’t installed, the command fails with a clear message.

### Engine config (in your application)

```yaml
# config/packages/survos_translator.yaml
survos_translator:
  default_engine: libre_local
  cache:          # optional cache (recommend a long-lived pool)
    pool: 'cache.translator'
    ttl:  604800  # 7 days
  engines:
    libre_local:
      type: libre
      base_uri: 'http://localhost:5000'
      api_key: null

    deepl_pro:
      type: deepl
      plan: pro                # or "free"; sets default host if base_uri missing
      api_key: '%env(DEEPL_API_KEY)%'

    google:
      type: google
      api_key: '%env(GOOGLE_TRANSLATE_KEY)%'
```

> **DeepL tip:** `DEEPL_API_KEY` must be the **raw key** (no `DeepL-Auth-Key ` prefix). `plan: pro` uses `https://api.deepl.com`; `plan: free` uses `https://api-free.deepl.com` unless you override `base_uri`.

---

## Commands (bundle)

| Command                            | Purpose                                                                |                                                                                |
| ---------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `code:translatable:trait`          | Generate `*TranslationsTrait` and convert fields to property hooks.    |                                                                                |
| `babel:translatables:dump`         | Dump the compile‑time index of translatable classes/fields.            |                                                                                |
| `babel:browse <Entity> [--locale]` | Print translated fields for an entity in a locale (good sanity check). |                                                                                |
| \`babel\:translate \[--engine      | --all]\`                                                               | Translate missing rows via events, then (optionally) TranslatorBundle engines. |

All Survos commands use Symfony 7.3 **invokable** style with attribute arguments/options.

---

## Debugging & troubleshooting

* **Nothing listed by `babel:translatables:dump`** — ensure compiler passes are running; clear cache. Entities/traits should import `#[Translatable]` from this bundle.
* **`EngineResolver failed for browse`** — add `#[BabelStorage]` to your entity so property‑mode storage is selected.
* **Hydrator warning: hooks missing** — ensure your entity `use`s `BabelHooksTrait` (or provides equivalent methods). Legacy `_i18n` is no longer supported.
* **DeepL 400 “Parameter 'text' not specified.”** — we send form-encoded `text`; if you overrode the engine, ensure it uses `text=<string>` (not array) for single translate.
* **SQLite locks** — consider `?busy_timeout=5000` and keep the queued writes + postFlush drainer enabled.

To see verbose compile-time logs during container build, run cache warmup with `-vvv`. At runtime, increase the app logger to `debug`.

---

## Advanced

* **Trait‑owned fields** — fields declared *inside* a trait can be marked `#[Translatable]`; the generator won’t move them (PHP can’t redeclare), but they are indexed and work with Babel.
* **Statuses** — add a status column/enum in your app’s `StrTranslation` to track review states; the translator pipeline can update it (e.g., `MACHINE` → `HUMAN`).
* **Search emitters** — in your normalizers, read already‑resolved properties to emit localized search documents.

---

## License

MIT
