# Examples

## Product entity (canonical)

```php
class Product implements BabelHooksInterface
{
    use BabelHooksTrait;

    public ?string $titleBacking = null;

    #[Translatable]
    public ?string $title {
        get => $this->resolveTranslatable('title', $this->titleBacking);
        set => $this->titleBacking = $value;
    }
}
```

This is the pattern.
Everything else is variation.

---

## Translation via event listener (recommended)

Babel emits a `TranslateStringEvent`.

You are expected to:

- supply translations if you have them
- or ignore the event

### Example listener

```php
#[AsEventListener]
final class MyTranslateListener
{
    public function __invoke(TranslateStringEvent $event): void
    {
        // You already have translations somewhere
        // or call your favorite API here

        // $event->translated = '...';
    }
}
```

This keeps Babel:
- provider-agnostic
- testable
- deterministic

---

## Why events instead of commands?

Commands:
- change
- drift
- get renamed

Events:
- are stable
- composable
- work in CLI, HTTP, Messenger

Use commands for inspection.
Use events for behavior.
