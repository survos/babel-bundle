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
 * PostLoad hydrator for string-backed translations.
 *
 * IMPORTANT: BabelHooksTrait resolves via $_resolved and BabelRuntime,
 * not $_i18n. So we populate $_resolved (via setResolvedTranslation) and
 * optionally tCodes[field] = hash for later runtime lookups.
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

        // Only entities in the compile-time index
        $fields = $this->index->fieldsFor($class);
        if ($fields === []) return;

        // We require the hook API to retrieve the backing (original) value
        if (!\method_exists($entity, 'getBackingValue')) {
            $this->logger->warning('Babel Hydrator: entity missing hooks API; skipping.', [
                'class'=>$class,
            ]);
            assert(false, "Missing hooks API in " . $entity::class);
            return;
        }

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // Source locale for hashing (NOT the request locale): BabelLocale accessor or default
        $srcLocale = $this->resolveSourceLocale($entity, $class);

        // Display locale (what we want to show now)
        $displayLocale = $this->locale->get();

        // Compute hashes per field from backing + context
        $fieldToHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') continue;

            $ctx  = $fieldCfg[$field]['context'] ?? null;
            $hash = BabelHasher::forString($srcLocale, $ctx, $original);

            $fieldToHash[$field] = $hash;

            // Maintain tCodes if present (optional, not persisted unless mapped)
            if (\property_exists($entity, 'tCodes')) {
                $codes = (array)($entity->tCodes ?? []);
                $codes[$field] = $hash;
                $entity->tCodes = $codes;
            }

            $this->logger->debug('Babel Hydrator hash', [
                'class'=>$class, 'field'=>$field, 'src'=>$srcLocale, 'ctx'=>$ctx, 'hash'=>$hash
            ]);
        }
        if ($fieldToHash === []) return;

        // Fetch just the display-locale rows
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
            'SELECT hash, text FROM str_translation WHERE hash IN (?) AND locale = ?',
            [\array_values($fieldToHash), $displayLocale],
            [ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING]
        )->fetchAllAssociative();

        $byHash = [];
        foreach ($rows as $r) {
            $h = (string)($r['hash'] ?? '');
            $t = $r['text'] ?? null;
            if ($h !== '') $byHash[$h] = \is_string($t) ? $t : null;
        }

        // Fill the runtime cache used by the property hook
        $setResolved = \method_exists($entity, 'setResolvedTranslation');
        foreach ($fieldToHash as $field => $hash) {
            if (!\array_key_exists($hash, $byHash)) {
                $this->logger->warning('Babel hydration: no StrTranslation row found for hash', [
                    'class'=>$class, 'field'=>$field, 'hash'=>$hash,
                    'displayLocale'=>$displayLocale, 'srcLocale'=>$srcLocale
                ]);
                continue;
            }
            if ($setResolved) {
                $entity->setResolvedTranslation($field, $byHash[$hash]);
            } else {
                // Last-resort: place into a conventional map the resolver might read
                if (!\property_exists($entity, '_i18n') || !\is_array($entity->_i18n ?? null)) {
                    $entity->_i18n = [];
                }
                $entity->_i18n[$displayLocale][$field] = $byHash[$hash];
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
                if (\is_string($v) && $v !== '') return $v;
            }
            if ($acc['type'] === 'method' && \method_exists($entity, $acc['name'])) {
                $v = $entity->{$acc['name']}();
                if (\is_string($v) && $v !== '') return $v;
            }
        }
        return $this->locale->getDefault();
    }
}
