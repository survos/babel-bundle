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
 * Legacy paths like writing to $_i18n are NOT supported anymore (we throw).
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

        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            $this->logger?->debug('BabelPostLoadHydrator: no translatable fields.', ['class' => $class]);
            return;
        }

        // Enforce hooks API (no legacy fallback)
        if (!\method_exists($entity, 'getBackingValue') || !\method_exists($entity, 'setResolvedTranslation')) {
            throw new \LogicException(sprintf(
                'Entity %s must use TranslatableHooksTrait (getBackingValue/setResolvedTranslation) for hydration.',
                $class
            ));
        }

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // Source locale used for hashing on write; prefer entity->srcLocale if present
        $current   = $this->locale->get();
        $default   = \method_exists($this->locale, 'getDefault') ? (string)$this->locale->getDefault() : $current;
        $srcLocale = (\property_exists($entity, 'srcLocale') && \is_string($entity->srcLocale) && $entity->srcLocale !== '')
            ? $entity->srcLocale
            : $default;

        // Compute hashes for all fields from raw/backing values
        $fieldToHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }
            $context = $fieldCfg[$field]['context'] ?? null;
            $fieldToHash[$field] = BabelHasher::forString($srcLocale, $context, $original);
        }

        if ($fieldToHash === []) {
            $this->logger?->debug('BabelPostLoadHydrator: no originals to hash.', ['class' => $class]);
            return;
        }

        // Fetch only the current target locale
        $conn = $em->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->select('hash', 'text')
            ->from('str_translation')
            ->where($qb->expr()->in('hash', ':hashes'))
            ->andWhere('locale = :loc')
            ->setParameter('hashes', array_values($fieldToHash), ArrayParameterType::STRING)
            ->setParameter('loc', $current);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $map = [];
        foreach ($rows as $r) {
            $h = (string)($r['hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($h !== '' && \is_string($t)) {
                $map[$h] = $t;
            }
        }

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
            'applied' => $applied,
            'locale'  => $current,
            'fields'  => array_keys($fieldToHash),
        ]);
    }
}
