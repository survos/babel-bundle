<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\BabelBundle\Util\BabelHasher;

/**
 * Hydrates $_i18n[locale][field] using the compile-time index.
 * - Computes hashes with the SAME triple as write-side: (srcLocale|context|original).
 * - srcLocale resolution: BabelLocale accessor (prop/method) OR DEFAULT locale.
 * - Fetches all translations for those hashes in a single IN() query.
 * - Does NOT pick a “selected” locale; property hooks should consult LocaleContext at access-time.
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

        $cfg      = $this->index->configFor($class);
        $fieldCfg = \is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];

        // Resolve srcLocale identically to write-side (BabelLocale → default)
        $srcLocale = (function(object $entity, TranslatableIndex $index, LocaleContext $lc, string $class): string {
            $acc = $index->localeAccessorFor($class);
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
            return $lc->getDefault();
        })($entity, $this->index, $this->locale, $class);

        // Build hashes for all fields with a non-empty backing
        if (!\method_exists($entity, 'getBackingValue')) {
            // No hooks API; nothing to hydrate
            return;
        }

        $fieldToHash = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') continue;
            $ctx   = $fieldCfg[$field]['context'] ?? null;
            $hash  = BabelHasher::forString($srcLocale, $ctx, $original);
            $fieldToHash[$field] = $hash;
        }
        if ($fieldToHash === []) return;

        // Fetch translations (DBAL 4, array binding)
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
            'SELECT hash, locale, text FROM str_translation WHERE hash IN (?)',
            [array_values($fieldToHash)],
            [ArrayParameterType::STRING]
        )->fetchAllAssociative();

        // Optional dev assert to catch mismatches early
        $present = array_column($rows, 'hash');
        $missing = array_values(array_diff(array_values($fieldToHash), $present));
        assert(
            \count($missing) === 0,
            "Babel: missing translation hashes for {$class}.\n"
            . "  missing: " . implode(',', $missing) . "\n"
            . "  tip: run: bin/console babel:translations:ensure --all\n"
            . "  note: ensure #[BabelLocale] accessor matches write-side hashing (or fallback default)."
        );

        // Bucket rows and assign into $_i18n
        $byHashLocale = [];
        foreach ($rows as $r) {
            $h = (string)($r['hash'] ?? '');
            $l = (string)($r['locale'] ?? '');
            $v = $r['text'] ?? null;
            if ($h !== '' && $l !== '') {
                $byHashLocale[$h][$l] = \is_string($v) ? $v : null; // keep NULL if untranslated
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
