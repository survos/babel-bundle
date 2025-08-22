# SurvosBabelBundle

> **A pragmatic, attribute‑driven translation bundle for Symfony 7.3 / PHP 8.4**
>
> • Marks translatable fields with attributes → generates property hooks → writes source strings and translations to normalized `str` / `str_translation` tables.
>
> • Designed for **automated translation workflows** (e.g. LibreTranslate) and **search indexing** (e.g. Meilisearch), not for coupled per‑entity join tables.

## Why another translation bundle?

**SurvosBabelBundle** focuses on:

* **Source‑of‑truth normalization**: all unique strings live once in `str`, translations in `str_translation` (composite PK of `hash, locale`).
* **Attribute → code generation**: use `#[Translatable]` on properties; the **SurvosCodeBundle** command will convert to modern **property hooks** and create `*TranslationsTrait` for you.
* **Automatable**: the bundle provides placeholder rows for all `framework.enabled_locales`, making it ideal to batch translate “missing” strings.
* **Robust at load time**: entity properties remain simple; Doctrine listeners fetch/resolve translations at `postLoad`, so your app reads translated text directly.
* **Works great with search**: inject translations at normalization time (e.g. for Meilisearch), 1 index per language.

> If you’ve used Gedmo/DoctrineExtensions: this bundle is simpler operationally (no join tables per entity), optimized for automated translation pipelines, and leans on PHP 8.4’s **property hooks** for a clean DX.

---

## Requirements

* PHP **8.4**
* Symfony **7.3**
* Doctrine ORM **3.x**
* Bundles:

    * `survos/babel-bundle` (this bundle)
    * `survos/code-bundle` (code generator for property hooks and traits)

> SQLite and PostgreSQL are supported. For SQLite we queue writes and drain on `postFlush` to avoid locks; Postgres is recommended in production.

---

## Quickstart

```bash
# 1) New app
symfony new --webapp babel-demo && cd babel-demo

# 2) DB (SQLite is fine to try)
echo 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"' > .env.local

# 3) Install
composer require survos/babel-bundle:dev-main
composer require survos/code-bundle --dev

# 4) Framework locales
cat > config/packages/translation.yaml <<'YAML'
framework:
  default_locale: en
  enabled_locales: ['en', 'de', 'es']
  translator:
    default_path: '%kernel.project_dir%/translations'
    fallbacks: ['en']
YAML

# 5) Create a simple entity with a translatable field
php bin/console make:entity Post -n
#   Add: title (string, nullable), body (text, nullable)
#   Then mark them with #[Translatable] in src/Entity/Post.php (see example below)

# 6) Generate the property hooks and trait
bin/console code:translatable:trait

# 7) Create/Update schema
bin/console doctrine:schema:update --force

# 8) Load some demo rows (write a tiny command or tinker in tinkerwell)
#    Then sanity check:
bin/console babel:translatables:dump
bin/console babel:browse Post en
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

* A trait `src/Entity/Translations/PostTranslationsTrait.php` is created with **property hooks** and a private backing (e.g. `$titleBacking`).
* Your entity will now `use PostTranslationsTrait` and (optionally) `TranslatableHooksTrait`.
* You keep writing `$post->title = 'Hello';` — the hook stores backing + the listener computes the **deterministic hash**, writes `str`/`str_translation`, and populates placeholders for other locales.

---

## How it works (high‑level)

1. **Mark fields** with `#[Translatable]` (in entities *or* traits). Optionally, mark the source locale property with `#[BabelLocale]`.
2. **Generate hooks** with `code:translatable:trait` (from SurvosCodeBundle). This converts public props to **property hooks** with `$<field>Backing` and a trait. No manual boilerplate.
3. **Compiler pass** builds an index of translatable fields (class + traits). No YAML required.
4. **Doctrine listener** (`prePersist/preUpdate`) computes a **hash** per field (`xxh3(srcLocale\0context\0text)`), writes/queues `str` and **source** `str_translation`, and creates **placeholder rows** for all `framework.enabled_locales` (except the source).
5. On **`postLoad`**, the listener resolves the locale‑specific text and exposes it via the hook getter, so `$entity->title` is already translated.

### Tables

* `str(hash, original, src_locale, context, meta, created_at, updated_at)`
* `str_translation(hash, locale, text, meta, created_at, updated_at[, status])`

    * Optional `status` enum (e.g. `untranslated | machine | human | reviewed`) — recommended for batch workflows

---

## Commands

| Command                          | What it does                                                                                                                  |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `code:translatable:trait [path]` | **Generate** `<Entity>TranslationsTrait` and update entities. Scans traits too; only converts fields **owned by the entity**. |
| `babel:translatables:dump`       | Dump the **compiler‑pass index** of translatable classes/fields (great for debugging).                                        |
| `babel:browse <Entity> <locale>` | Print translated fields for a given entity/locale (sanity check / reality check).                                             |
| `babel:populate`                 | Example/scaffold command to populate/seed strings (if present in your app).                                                   |
| `babel:translate:missing`        | Translate missing entries using LibreTranslate (if you’ve wired `survos/libre-translate-bundle`).                             |

> List all commands: `bin/console | grep -E '(babel|code:translatable)'`

---

## Installation & Wiring Details

1. **Install bundles** (Babel + Code)
2. **Ensure locales** in `config/packages/translation.yaml`:

   ```yaml
   framework:
     default_locale: en
     enabled_locales: ['en','de','es']
   ```
3. **App entities for Str/StrTranslation**

    * The generator will scaffold `App\Entity\Str` and `App\Entity\StrTranslation` (extending Babel’s bases) **if missing**.
4. **Enabled locales → placeholders**

    * Placeholders for other locales are created if `TranslationStore` receives `%kernel.enabled_locales%`.
    * If you’re embedding this bundle, make sure your service wiring sets:

      ```php
      $builder->autowire(TranslationStore::class)
          ->setAutowired(true)
          ->setAutoconfigured(true)
          ->setPublic(true)
          ->setArgument('$enabledLocales', '%kernel.enabled_locales%');
      ```
5. **SQLite note**

    * On SQLite, DBAL writes are **queued** during ORM flush and **drained in `postFlush`** to avoid lock errors. Consider adding `?busy_timeout=5000` to the DSN for a smoother dev experience.

---

## Example Walkthrough

Let’s translate a `Post` entity with `title` and `body`.

1. Mark fields with `#[Translatable]` and run:

   ```bash
   bin/console code:translatable:trait
   bin/console doctrine:schema:update --force
   ```
2. Persist a few posts with English text (the source):

   ```php
   $p = new Post();
   $p->title = 'Hello';
   $p->body  = 'This is an example.';
   $em->persist($p); $em->flush();
   ```

   This writes:

    * `str(hash='…', original='Hello', src_locale='en', context='post.title')`
    * `str_translation(hash='…', locale='en', text='Hello')`
    * placeholders: `('…','de','')`, `('…','es','')`, …
3. View in another locale:

   ```bash
   bin/console babel:browse Post es
   ```

   You’ll see the Spanish text if present, otherwise the English fallback.
4. Translate missing:

   ```bash
   bin/console babel:translate:missing --from=en --to=es --limit=100
   ```

   (Wire `survos/libre-translate-bundle` and the provided command will mark status and fill text.)

---

## Differences vs Gedmo / DoctrineExtensions

* **Storage model**: single `str`/`str_translation` for all strings across your app (hash‑addressed), not per‑entity join tables.
* **Source first**: focuses on **capturing source text** and generating **translation tasks** (placeholders) for target locales.
* **Automation‑friendly**: designed to batch translate “missing” rows, capture `status`, and iterate.
* **Property hooks**: modern PHP 8.4 hooks keep your entity API ergonomic (`$obj->title`) without custom getters/setters.
* **Search ready**: easy to emit normalized, locale‑specific documents for indexing.

---

## Debugging / Tips

* **See what the compiler pass found**:

  ```bash
  bin/console babel:translatables:dump -vvv
  ```
* **Enable verbose compiler logging during cache warmup** (if supported in your build):

  ```bash
  SURVOS_BABEL_DEBUG=1 bin/console cache:clear -vvv
  ```
* **Check what’s being upserted**: run your load with `-vvv`; the listener logs `schedule STR/TR …` and the store logs `SQL UPSERT …`.
* **SQLite**: if you see lock warnings, ensure you’re on the branch with **queue + drain** and consider `?busy_timeout=5000` in your DSN.

---

## Advanced

* **Custom status enum**: add a `status` enum to `App\Entity\StrTranslation` (e.g. `UNTRANSLATED|MACHINE|HUMAN|REVIEWED`), and the bundle will set `UNTRANSLATED` on placeholders.
* **Trait‑owned fields**: the generator now detects `#[Translatable]` in **traits**. It does **not** move trait‑owned fields (PHP won’t allow redeclaration); they still work with Babel without conversion.
* **Locale detection per entity**: add a `#[BabelLocale]` attribute on a property that contains the source locale; otherwise `default_locale` is used.

---

## Roadmap

* First‑class translate‑missing flows with provider adapters (LibreTranslate, DeepL, etc.)
* Admin tooling for reviewing translations and statuses
* Schema option to store context/domain in a separate table for analytics

---

## License

MIT

---

## Should we keep this chat for translating next?

Either works! If you’ll keep iterating on this app, **staying in this thread** helps preserve context (entities, commands, and your env). If you want a clean slate for a dedicated “translate‑missing” command walkthrough, start a new chat and link back to this README.
