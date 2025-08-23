<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Service\TranslatableIndex;

/**
 * Hydrates string-backed translations on PostLoad using the compile-time index.
 *
 * Assumptions:
 *  - TranslatableIndex contains this FQCN with 'fields' = fieldName => meta
 *  - Each field uses a backing prop named "<field>_backing" (public, PHP 8.4 hooks OK)
 *  - Hash algo matches write-side subscriber: sha1($original)
 *  - Host entity exposes public array $_i18n assigned as $_i18n[locale][field] = text
 *  - Canonical StrTranslation mapping (hash, locale, text) exists
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public function __construct(
        private readonly TranslatableIndex $index,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $entity = $args->getObject();
        $fqcn   = $entity::class;

        $fields = $this->index->fieldsFor($fqcn);
        if ($fields === []) {
            return;
        }

        // 2) Collect hashes for all fields that have a non-empty backing value
        $fieldToHash = [];
        foreach ($fields as $field) {
            $backing = $field . '_backing';
            if (!\property_exists($entity, $backing)) {
                continue;
            }
            $original = $entity->$backing ?? null;
            if (!\is_string($original) || $original === '') {
                continue;
            }
            $fieldToHash[$field] = sha1($original);
        }
        if ($fieldToHash === []) {
            return;
        }

        // 3) Single IN query for all hashes → rows: [hash, locale, text]
        $trRepo = $em->getRepository(StrTranslation::class);
        $rows = $trRepo->createQueryBuilder('t')
            ->select('t.hash AS hash', 't.locale AS locale', 't.text AS text')
            ->andWhere('t.hash IN (:hashes)')
            ->setParameter('hashes', array_values($fieldToHash))
            ->getQuery()->getArrayResult();

        // 4) Bucket by hash/locale
        $byHashLocale = [];
        foreach ($rows as $r) {
            $h = (string) ($r['hash'] ?? '');
            $l = (string) ($r['locale'] ?? '');
            $v = $r['text'] ?? null;
            if ($h !== '' && $l !== '') {
                $byHashLocale[$h][$l] = \is_string($v) ? $v : '';
            }
        }

        // 5) Ensure $_i18n exists and fill it
        if (!\property_exists($entity, '_i18n') || !\is_array($entity->_i18n ?? null)) {
            $entity->_i18n = [];
        }

        foreach ($fieldToHash as $field => $hash) {
            if (!isset($byHashLocale[$hash])) {
                continue;
            }
            foreach ($byHashLocale[$hash] as $locale => $text) {
                $entity->_i18n[$locale][$field] = $text;
            }
        }
    }
}
