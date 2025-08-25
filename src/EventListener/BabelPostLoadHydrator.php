<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\BabelHasher;

/**
 * Hydrates resolved translations via TranslatableHooksTrait methods (no reflection).
 * Requirements on the entity:
 *  - getBackingValue(string $field): mixed
 *  - setResolvedTranslation(string $field, string $text): void
 *
 * Strategy:
 *  1) Compute per-field hashes based on source-locale + context + original backing value
 *  2) Fetch ONLY the current request locale's translations for those hashes
 *  3) Write them via setResolvedTranslation($field, $text)
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public function __construct(
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $entity = $args->getObject();
        $class  = $entity::class;

        // What fields should we hydrate?
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            // nothing to do for this class
            return;
        }

        // Enforce hooks API (no legacy _i18n / no reflection)
        if (!\method_exists($entity, 'getBackingValue') || !\method_exists($entity, 'setResolvedTranslation')) {
            throw new \LogicException(sprintf(
                'Entity %s must use TranslatableHooksTrait (getBackingValue/setResolvedTranslation) for hydration.',
                $class
            ));
        }

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // Source-locale for hashing; prefer #[BabelLocale] (recorded as localeProp) else bundle default
        $srcLocale = $this->resolveSourceLocale($entity, $class);

        // Build hash per field from backing (avoid reading the public hook to prevent recursion)
        $fieldToHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }
            $context = $fieldCfg[$field]['context'] ?? null;
            $hash    = BabelHasher::forString($srcLocale, $context, $original);
            $fieldToHash[$field] = $hash;
        }

        if ($fieldToHash === []) {
            $this->logger?->debug('BabelPostLoadHydrator: no originals to hash', ['class' => $class]);
            return;
        }

        // Fetch translations only for the current target locale
        $targetLocale = $this->locale->get();

        $conn = $em->getConnection();
        $qb   = $conn->createQueryBuilder();
        $qb->select('hash', 'text')
            ->from('str_translation')
            ->where($qb->expr()->in('hash', ':hashes'))
            ->andWhere('locale = :loc')
            ->setParameter('hashes', array_values($fieldToHash), ArrayParameterType::STRING)
            ->setParameter('loc', $targetLocale);
//        $query = $qb->getSQL(); dd($qb->getSQL(), $targetLocale);

        $rows = $qb->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) { dump($row); }

        // Build hash => text map (skip nulls)
        $map = [];
        foreach ($rows as $r) {
            $h = (string)($r['hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($h !== '' && \is_string($t)) {
                $map[$h] = $t;
            }
        }

        // Apply via hooks
        $applied = 0;
        foreach ($fieldToHash as $field => $hash) {
            $text = $map[$hash] ?? null;
            if (!\is_string($text) || $text === '') {
                continue;
            }
            $entity->setResolvedTranslation($field, $text);
            $applied++;
        }


        $this->logger?->debug('BabelPostLoadHydrator: hydrated', [
            'class'   => $class,
            'locale'  => $targetLocale,
            'applied' => $applied,
            'fields'  => array_keys($fieldToHash),
        ]);
    }

    /**
     * Resolve source-locale used for hashing:
     * - prefers TranslatableIndex::configFor($class)['localeProp'] (from #[BabelLocale])
     * - tries a conventional getter first, then a public property
     * - falls back to bundle default (not the request/UI locale)
     */
    private function resolveSourceLocale(object $entity, string $class): string
    {
        $current = $this->locale->get();
        $default = \method_exists($this->locale, 'getDefault') ? (string)$this->locale->getDefault() : $current;

        $cfg  = $this->index->configFor($class);
        $prop = \is_string($cfg['localeProp'] ?? null) ? $cfg['localeProp'] : null;

        if ($prop !== null && $prop !== '') {
            $getter = 'get' . \ucfirst($prop);
            if (\method_exists($entity, $getter)) {
                try {
                    $val = $entity->$getter();
                    if (\is_string($val) && $val !== '') {
                        return $val;
                    }
                } catch (\Throwable) {
                    // ignore and fall through
                }
            }
            if (\property_exists($entity, $prop)) {
                $val = $entity->$prop ?? null;
                if (\is_string($val) && $val !== '') {
                    return $val;
                }
            }
        }

        return $default;
    }
}
