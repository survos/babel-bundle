<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

/**
 * PostLoad hydrator for string-backed translations.
 *
 * Looks up TR rows by (str_hash, locale).
 * IMPORTANT: If displayLocale === srcLocale, we MUST use the original/backing text
 * and never query str_translation (no nl->nl "translation").
 */
#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public function __construct(
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
        private readonly LoggerInterface $logger
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $entity = $args->getObject();
        $class  = $entity::class;

        if (!$entity instanceof BabelHooksInterface) {
            return;
        }

        $fields = $this->index->fieldsFor($class);
        if ($fields === []) {
            return;
        }

        // Source locale for hashing (NOT the request/display locale)
        $srcLocale = HashUtil::normalizeLocale($this->resolveSourceLocale($entity, $class));

        // What we want to display right now
        $displayLocale = HashUtil::normalizeLocale($this->locale->get());

        $this->logger->warning('BabelPostLoadHydrator locales', [
            'class' => $class,
            'srcLocale' => $srcLocale,
            'displayLocale' => $displayLocale,
            'requestLocale' => $this->locale->get(), // same call, but leave for clarity
        ]);

        assert(method_exists($entity, 'setResolvedTranslation'), "invalid entity, needs to implement BabelHooksInterface");


        // If we're displaying the source locale, short-circuit: use backing/original values.
        // No translation rows should exist for (str_hash, locale==srcLocale).
        if ($displayLocale !== '' && $srcLocale !== '' && $displayLocale === $srcLocale) {
            foreach ($fields as $field) {
                $original = $entity->getBackingValue($field);
                if (!\is_string($original) || $original === '') {
                    continue;
                }

                $entity->setResolvedTranslation($field, $original);
            }
            $this->logger->warning('BabelPostLoadHydrator locales', [
                'class' => $class,
                'srcLocale' => $srcLocale,
                'displayLocale' => $displayLocale,
                'requestLocale' => $this->locale->get(), // same call, but leave for clarity
            ]);

            return;
        }

        // Compute canonical STR hashes per field from backing + source locale
        $fieldToStrHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            $strHash = HashUtil::calcSourceKey($original, $srcLocale);
            $fieldToStrHash[$field] = $strHash;

            // Maintain tCodes if present (optional)
            // this has been moved to a dedicated listener for array-backed translations.
//            if (\property_exists($entity, 'tCodes')) {
//                $codes = (array) ($entity->tCodes ?? []);
//                $codes[$field] = $strHash;
//                $entity->tCodes = $codes;
//            }
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
            $k = (string) ($r['str_hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($k !== '') {
                $byStrHash[$k] = \is_string($t) ? $t : null;
            }
        }

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

                if (!$text) {
                    // we might not have a translation yet, so leave it as null so the fallback happens.  We could also tag this as __ to flag it.
//                    $text = '__' . $field . '__NOT_TRANSLATED';
                    continue;
//                    dd($byStrHash, $field, $text, $strHash, $class, $displayLocale, $srcLocale);
                }
                $entity->setResolvedTranslation($field, $text);
        }
    }

    /** Prefer compile-time class-level locale; then accessor; then default. */
    private function resolveSourceLocale(object $entity, string $class): string
    {
        $cfg = $this->index->configFor($class) ?? [];
        if (\is_string($cfg['sourceLocale'] ?? null) && $cfg['sourceLocale'] !== '') {
            return $cfg['sourceLocale'];
        }

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
