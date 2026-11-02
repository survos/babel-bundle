<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Search;

use Doctrine\ORM\QueryBuilder;
use Survos\SearchBundle\Adapter\AdapterProvider;
use Survos\SearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Survos\SearchBundle\Event\PreSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Registered only when SearchBundle is installed; no application-specific search wiring. */
final readonly class BabelSearchSubscriber implements EventSubscriberInterface
{
    /** @var \WeakMap<object, \Closure> */
    private \WeakMap $installed;

    public function __construct(private AdapterProvider $adapters, private TranslatedFieldPredicate $translations)
    {
        $this->installed = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [PreSearchEvent::class => 'onSearch'];
    }

    public function onSearch(PreSearchEvent $event): void
    {
        $search = $event->getSearch();
        if (!$this->adapters->getAdapter($search->getAdapterName()) instanceof DoctrineAdapter) {
            return;
        }
        $parameters = $search->getAdapterParameters();
        $previous = $parameters[DoctrineAdapter::SEARCH_FIELD_PREDICATE] ?? null;
        // Searches may be reused by workers and tests. Do not wrap the same integration twice.
        if (isset($this->installed[$search]) && $this->installed[$search] === $previous) {
            return;
        }
        $parameters[DoctrineAdapter::SEARCH_FIELD_PREDICATE] = null === $previous
            ? $this->translations->predicate(...)
            : fn (QueryBuilder $qb, string $field, string $parameter): ?string => $previous($qb, $field, $parameter) ?? $this->translations->predicate($qb, $field, $parameter);
        $this->installed->offsetSet($search, $parameters[DoctrineAdapter::SEARCH_FIELD_PREDICATE]);
        $search->setAdapterParameters($parameters);
    }
}
