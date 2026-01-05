# Commands

This page documents **only commands that are still relevant**.

If a command is not listed here, assume it is:
- legacy
- experimental
- or pending removal

---

## Start here

```bash
bin/console babel:debug
```

This command answers:
- Did Babel index anything?
- Which locales are active?
- Which carriers exist?
- Is cache involved?

---

## Discovery

### babel:carriers

Lists all carrier classes grouped by storage mode.

Use this to confirm:
- your entity is visible
- `BabelHooksInterface` is detected

### babel:scan

Runtime scan of `#[Translatable]` fields.

Useful when debugging naming or trait issues.

---

## Inspection

### babel:stats

High-level translation coverage.

### babel:str

Browse source strings.

### babel:tr

Browse translations.

These commands are safe and schema-accurate.

---

## Translation commands (⚠️ unstable)

### babel:translate

⚠️ DO NOT rely on this yet.

It still references legacy field names and is pending refactor.

Use events instead.

---

## Recommended workflow

1. `babel:debug`
2. `babel:carriers`
3. `babel:scan`
4. `babel:stats`

If something fails before step 3, fix your entity.
