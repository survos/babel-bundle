<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Service\TranslationStore;
use ReflectionProperty;

/**
 * Replaces public properties marked #[Translatable] with the target-locale text on postLoad.
 * No doctrine-extensions needed.
 *
 * NOTE: currentLocale & fallbackLocale are injected in the bundle class (override in your app if needed).
 */
final class TranslatableSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly string $currentLocale,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::postLoad];
    }

    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $rc = new \ReflectionClass($entity);

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(Translatable::class);
            if (!$attrs) continue;

            $value = $prop->getValue($entity);
            if (!is_string($value) || $value === '') continue;

            $meta = $attrs[0]->newInstance();
            // Compute hash from source string + fallback locale (assuming the source is stored in fallbackLocale)
            $hash = $this->store->hash($value, $this->fallbackLocale, $meta->context);

            $translated = $this->store->get($hash, $this->currentLocale) ?? $value;
            $prop->setValue($entity, $translated);
        }
    }
}
