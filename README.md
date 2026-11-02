# SurvosBabelBundle

SurvosBabelBundle provides **normalized translation storage** with **runtime locale resolution** using **PHP 8.4 property hooks**.

This bundle is intentionally opinionated and **not plug-and-play**. It is designed for:

- applications with many translatable entities,
- batch translation workflows,
- search indexing per locale,
- avoiding per-entity translation join tables.

If you want “EntityTranslation tables per entity”, this is not that bundle.

---

## Core idea (read this first)

Every translatable field has **two representations**:

1. A **persisted backing field** (the source text)
2. A **runtime property hook** (the localized value)

Example:

```php
$product->title = 'Chair';     // write source text
echo $product->title;          // read localized text (if available)
```

Only the **backing field** is persisted.
The localized value is resolved at runtime.

---

## Hard requirements (non-negotiable)

If you violate any of these, Babel will not work.

### 1. PHP 8.4 property hooks

This bundle **requires PHP 8.4**.

### 2. BabelHooksInterface **must** be implemented

Entities using Babel **must** implement:

```php
Survos\BabelBundle\Contract\BabelHooksInterface
```

The easiest way is to use the provided trait:

```php
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;
```

⚠️ The compiler pass relies on this interface.
If it is missing, the entity will not be indexed.

### 3. Naming convention is strict

For every logical field `foo`:

| Purpose | Name |
|------|------|
| Backing property | `fooBacking` |
| Runtime property | `foo` |

The compiler pass and runtime resolver **assume this naming**.

---

## Minimal working example

```php
<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;
use Survos\Lingua\Contracts\Attribute\Translatable;

#[ORM\Entity]
#[BabelStorage] // Property-hook storage mode
class Product implements BabelHooksInterface
{
    use BabelHooksTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?string $titleBacking = null;

    #[Translatable]
    public ?string $title {
        get => $this->resolveTranslatable('title', $this->titleBacking);
        set => $this->titleBacking = $value;
    }
}
```

---

## Database model (important)

Babel stores translations **outside your entities**.

### Source strings (`str`)

One row per unique source string + locale.

### Translations (`str_translation`)

One row per `(source, targetLocale)` pair.

Your entities never store translations directly.

---

## Commands (opinionated subset)

Only these commands are considered **current and supported**:

```bash
babel:debug
babel:carriers
babel:scan
babel:stats
babel:str
babel:tr
```

Everything else should be treated as **experimental or legacy**.

See `doc/commands.md` for details.

---

## ⚠️ Known broken / legacy areas (read before using)

### babel:translate

`babel:translate` currently references **obsolete field names**
(`hash`, `original`, `locale`, etc.).

It does **not** match the current entities:

- `StrBase`: `code`, `sourceLocale`, `source`, `context`, `meta`
- `StrTranslationBase`: `strCode`, `targetLocale`, `engine`, `text`, `status`

Until this is refactored, **do not rely on `babel:translate`**.

Use the event system instead (see docs).

### Code generation

All previous code generators for Babel have been **removed**.

They caused more confusion than value.

Documentation now reflects **manual, explicit setup only**.

---

## Documentation

- `doc/concepts.md` — mental model & invariants
- `doc/setup.md` — manual setup only
- `doc/commands.md` — what still matters
- `doc/examples.md` — Product + event listener
- `doc/troubleshooting.md` — common failures

---

## Philosophy

Babel is intentionally:

- explicit over magical
- inspectable over clever
- boring at runtime
- powerful at scale

Once wired correctly, it disappears into the background.

## Locale-aware Doctrine Instant Search

When both Babel and SearchBundle's `searchFieldPredicate` hook are installed,
Babel automatically supplies matching predicates for string-backed translatable
fields in Doctrine searches. Configure either `o.label` or `o.labelBacking` in
`DoctrineAdapter::SEARCH_FIELDS`; Babel uses its compiled `TranslatableIndex`.
No application-specific joins or translation generation are needed.

Matching follows the display locale: nonblank target translations are searched;
source text is searched when that locale is the source language or when the
translation is missing. Empty provider stubs never hide populated translations.
If multiple providers have nonblank text, the highest translation row ID wins
consistently in both hydration and search. Ordinary identifiers remain searchable.

The integration uses DQL source-set subqueries, so translation rows cannot multiply
hits, totals or facet counts. The same predicate runs before pagination and facet
self-exclusion. Source-language matching skips translation tables. Semantic search
continues to use its vector candidates, without applying literal keyword matching.
SQLite and DuckDB are covered by the same ORM regression tests.

The searched entity manager must map Babel's `Str` and `StrTranslation` entities.
Source locales can be fixed in Babel metadata, use the application default, or
come from a mapped property containing normalized locale codes. A computed method
accessor needs an explicit searchable locale property. Other search adapters and
installations without a compatible SearchBundle remain unchanged.
