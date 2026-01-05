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
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

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
