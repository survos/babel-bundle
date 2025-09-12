<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\HashUtil;

/**
 * PostLoad hydrator for string-backed translations.
 *
 * FIX: use the canonical STR key and look up TR rows by (str_hash, locale),
 * not by TR.primary-key. This matches our schema and HashUtil convention.
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public function __construct(
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
        private readonly LoggerInterface $logger
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $entity = $args->getObject();
        $class  = $entity::class;

        // Only entities registered in the index
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            return;
        }

        // Need the hook API to get the *backing* (original) values
        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel Hydrator: entity missing hooks API; skipping.', ['class' => $class]);
            return;
        }

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // Source locale for hashing (NOT the request/display locale)
        $srcLocale = $this->resolveSourceLocale($entity, $class);

        // What we want to display right now
        $displayLocale = $this->locale->get();

        // Compute canonical STR hashes per field from backing + source locale
        $fieldToStrHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            // NOTE: context no longer participates in key — HashUtil::calcSourceKey(original, src)
            // If you need context in the key again, reintroduce it in HashUtil and here.
            $strHash = HashUtil::calcSourceKey($original, $srcLocale);
            $fieldToStrHash[$field] = $strHash;

            // Maintain tCodes if present (optional)
            if (\property_exists($entity, 'tCodes')) {
                $codes = (array)($entity->tCodes ?? []);
                $codes[$field] = $strHash;
                $entity->tCodes = $codes;
            }

            $this->logger->debug('Babel Hydrator STR key', [
                'class' => $class, 'field' => $field, 'src' => $srcLocale, 'hash' => $strHash
            ]);
        }

        if ($fieldToStrHash === []) {
            return;
        }

        // Fetch translations for the display locale by (str_hash, locale)
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
            'SELECT str_hash, text 
               FROM str_translation 
              WHERE str_hash IN (?) AND locale = ?',
            [\array_values($fieldToStrHash), $displayLocale],
            [ArrayParameterType::STRING, ParameterType::STRING]
        )->fetchAllAssociative();

        $byStrHash = [];
        foreach ($rows as $r) {
            $k = (string)($r['str_hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($k !== '') {
                $byStrHash[$k] = \is_string($t) ? $t : null;
            }
        }

        // Fill the runtime cache used by property hooks
        $setResolved = \method_exists($entity, 'setResolvedTranslation');
        foreach ($fieldToStrHash as $field => $strHash) {
            if (!\array_key_exists($strHash, $byStrHash)) {
                $this->logger->warning('Babel hydration: no StrTranslation row found for (str_hash, locale)', [
                    'class'         => $class,
                    'field'         => $field,
                    'str_hash'      => $strHash,
                    'displayLocale' => $displayLocale,
                    'srcLocale'     => $srcLocale,
                ]);
                continue;
            }
            $text = $byStrHash[$strHash];

            if ($setResolved) {
                $entity->setResolvedTranslation($field, $text);
            } else {
                // last-resort stash
                if (!\property_exists($entity, '_i18n') || !\is_array($entity->_i18n ?? null)) {
                    $entity->_i18n = [];
                }
                $entity->_i18n[$displayLocale][$field] = $text;
            }
        }
    }

    /** Accessor defined in the compile-time index (prop/method) or fallback to default locale. */
    private function resolveSourceLocale(object $entity, string $class): string
    {
        $acc = $this->index->localeAccessorFor($class);
        if ($acc) {
            if ($acc['type'] === 'prop' && \property_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']} ?? null;
                if (\is_string($v) && $v !== '') {
                    return $v;
                }
            }
            if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']}();
                if (\is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        return $this->locale->getDefault();
    }
}
