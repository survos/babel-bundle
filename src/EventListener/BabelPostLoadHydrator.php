<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\BabelHasher;

/**
 * Hydrates $_i18n[locale][field] for string-backed fields using the compile-time index.
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public function __construct(
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $entity = $args->getObject();
        $class  = $entity::class;

        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            return;
        }

        $cfg = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // choose the same src used for hashing on write
        $src = null;
        if (\property_exists($entity, 'srcLocale')) {
            $src = \is_string($entity->srcLocale) && $entity->srcLocale !== '' ? $entity->srcLocale : null;
        }
        $srcLocale = $src ?? $this->locale->get();

        // Build hashes per field
        $fieldToHash = [];
        foreach ($fields as $field) {
            $backing = $field . '_backing';
            if (!\property_exists($entity, $backing)) continue;

            $original = $entity->$backing ?? null;
            if (!\is_string($original) || $original === '') continue;

            $context = $fieldCfg[$field]['context'] ?? null;
            $fieldToHash[$field] = BabelHasher::forString($srcLocale, $context, $original);
        }
        if ($fieldToHash === []) return;

        // Fetch translations for all hashes
        $repo = $em->getRepository(StrTranslation::class);
        $rows = $repo->createQueryBuilder('t')
            ->select('t.hash AS hash', 't.locale AS locale', 't.text AS text')
            ->andWhere('t.hash IN (:hashes)')
            ->setParameter('hashes', array_values($fieldToHash))
            ->getQuery()->getArrayResult();

        $byHashLocale = [];
        foreach ($rows as $r) {
            $h = (string) ($r['hash'] ?? '');
            $l = (string) ($r['locale'] ?? '');
            $v = $r['text'] ?? null;
            if ($h !== '' && $l !== '') {
                $byHashLocale[$h][$l] = \is_string($v) ? $v : null; // keep NULL if not translated yet
            }
        }

        if (!\property_exists($entity, '_i18n') || !\is_array($entity->_i18n ?? null)) {
            $entity->_i18n = [];
        }

        foreach ($fieldToHash as $field => $hash) {
            if (!isset($byHashLocale[$hash])) continue;
            foreach ($byHashLocale[$hash] as $loc => $text) {
                $entity->_i18n[$loc][$field] = $text;
            }
        }
    }
}
