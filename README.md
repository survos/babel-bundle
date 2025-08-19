# Survos BabelBundle

A tiny translation base with:

- Mapped-superclass entities: **StrBase**, **StrTranslationBase**
- `#[Translatable]` attribute
- Doctrine subscriber to populate translated text on `postLoad`
- No doctrine-extensions dependency

## How to use

1. **Install** (monorepo): add bundle to `config/bundles.php`:

```php
return [
    // ...
    Survos\BabelBundle\SurvosBabelBundle::class => ['all' => true],
];
