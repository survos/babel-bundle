# Concepts

This document explains **how Babel thinks**.
If you understand this page, the rest is mechanical.

---

## What Babel actually indexes

Babel does **not** index entities automatically.

It indexes:

- classes marked with `#[BabelStorage]`
- that implement `BabelHooksInterface`
- and expose `#[Translatable]` properties

This happens in a **compiler pass**.

If a class is not indexed at compile time,
it does not exist to Babel at runtime.

---

## Backing fields vs runtime fields

Example:

```php
public ?string $titleBacking = null;

#[Translatable]
public ?string $title { ... }
```

Rules:

- Only `titleBacking` is persisted
- `title` is **never persisted**
- `title` is resolved per locale at runtime

---

## How resolution works (simplified)

1. Doctrine loads the entity
2. Babel post-load hydrator runs
3. For each translatable field:
    - compute `(source, sourceLocale)`
    - look up translation for current locale
4. If found → return translated text
5. Else → return backing value

No writes occur during resolution.

---

## Why BabelHooksInterface exists

The interface signals:

> “This entity participates in Babel’s lifecycle.”

The compiler pass uses it as a hard filter.

This avoids accidentally scanning:
- DTOs
- admin projections
- read models
- third-party entities

---

## Storage modes (today)

Only one storage mode is considered stable:

- `StorageMode::Property` (default)

Anything else should be treated as internal experimentation.

---

## Key invariant (repeat)

If you remember only one rule:

> **Babel never mutates your entity’s backing values.**

Translations live elsewhere.
Resolution is always read-only.
