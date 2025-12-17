<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Contract\TranslatableByHashInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\Lingua\Core\Identity\HashUtil;

/**
 * PostLoad hydrator for pointer-driven (hash-based) translations.
 *
 * Target: proxy entities/models that maintain a field => str_hash pointer map.
 * Uses STR_TRANSLATION lookup by (str_hash, locale) and populates runtime cache
 * via setResolvedTranslation().
 *
 * Missing translations are left as null (caller fallback).
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelHashPointerPostLoadHydrator
{
    public function __construct(
        private readonly LocaleContext $locale,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TranslatableByHashInterface) {
            return;
        }

        $fieldToHash = $entity->getStrHashMap();
        if ($fieldToHash === []) {
            return;
        }

        $displayLocale = HashUtil::normalizeLocale($this->locale->get());
        if ($displayLocale === '') {
            return;
        }

        // Normalize and dedupe hashes; preserve field associations.
        $norm = [];
        foreach ($fieldToHash as $field => $hash) {
            $field = (string) $field;
            $hash  = (string) $hash;
            if ($field !== '' && $hash !== '') {
                $norm[$field] = $hash;
            }
        }
        if ($norm === []) {
            return;
        }

        $hashes = array_values(array_unique(array_values($norm)));

        $conn = $args->getObjectManager()->getConnection();
        $rows = $conn->executeQuery(
            'SELECT str_hash, text
               FROM str_translation
              WHERE str_hash IN (?) AND locale = ?',
            [$hashes, $displayLocale],
            [ArrayParameterType::STRING, ParameterType::STRING]
        )->fetchAllAssociative();

        $byHash = [];
        foreach ($rows as $r) {
            $h = (string) ($r['str_hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($h !== '') {
                $byHash[$h] = \is_string($t) ? $t : null;
            }
        }

        $miss = 0;
        foreach ($norm as $field => $hash) {
            if (!array_key_exists($hash, $byHash)) {
                $miss++;
                // leave null so caller falls back to backing/raw
                $entity->setResolvedTranslation($field, null);
                continue;
            }

            $text = $byHash[$hash];
            $entity->setResolvedTranslation($field, ($text !== null && $text !== '') ? $text : null);
        }

        if ($this->debug && $miss > 0) {
            $this->logger->debug('Babel hash-pointer hydration: missing translations', [
                'class' => $entity::class,
                'missing' => $miss,
                'total' => \count($norm),
                'locale' => $displayLocale,
            ]);
        }
    }
}
