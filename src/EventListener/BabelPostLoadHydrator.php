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
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

#[AsDoctrineListener(event: Events::postLoad)]
final class BabelPostLoadHydrator
{
    public const string DEFAULT_ENGINE = 'babel';

    public function __construct(
        private readonly TranslatableIndex $index,
        private readonly LocaleContext $locale,
        private readonly LoggerInterface $logger,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $conn   = $em->getConnection();
        $entity = $args->getObject();
        $class  = $entity::class;

        if (!$entity instanceof BabelHooksInterface) {
            return;
        }

        $displayLocale = HashUtil::normalizeLocale($this->locale->get());
        if ($displayLocale === '') {
            return;
        }

        // ------------------------------------------------------------------
        // 1) String-backed translatables (#[Translatable])
        // ------------------------------------------------------------------
        $fields = $this->index->fieldsFor($class);
        if ($fields !== []) {
            $srcLocale = HashUtil::normalizeLocale($this->resolveSourceLocale($entity, $class));
            if ($srcLocale === '') {
                $srcLocale = $this->locale->getDefault();
            }

            // If display locale equals source locale, short-circuit to backing values.
            if ($displayLocale === $srcLocale) {
                foreach ($fields as $field) {
                    $original = $entity->getBackingValue($field);
                    if (\is_string($original) && $original !== '') {
                        $entity->setResolvedTranslation($field, $original);
                    }
                }
            } else {
                $fieldToStrCode = [];
                foreach ($fields as $field) {
                    $original = $entity->getBackingValue($field);
                    if (!\is_string($original) || $original === '') {
                        continue;
                    }

                    // IMPORTANT: MUST MATCH flush subscriber strategy:
                    // $strCode = HashUtil::calcSourceKey($original, $srcLocale)
                    $strCode = HashUtil::calcSourceKey($original, $srcLocale);
                    $fieldToStrCode[$field] = $strCode;
                }

                if ($fieldToStrCode !== []) {
                    $sql = sprintf(
                        'SELECT tr.%s AS str_code, tr.%s AS text
                         FROM %s tr
                         WHERE tr.%s IN (?)
                           AND tr.%s = ?
                           AND tr.%s = ?',
                        BabelSchema::STR_TR_STR_CODE,
                        BabelSchema::STR_TR_TEXT,
                        BabelSchema::STR_TR_TABLE,
                        BabelSchema::STR_TR_STR_CODE,
                        BabelSchema::STR_TR_TARGET_LOCALE,
                        BabelSchema::STR_TR_ENGINE
                    );

                    $rows = $conn->executeQuery(
                        $sql,
                        [\array_values($fieldToStrCode), $displayLocale, self::DEFAULT_ENGINE],
                        [ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING]
                    )->fetchAllAssociative();

                    $byStrCode = [];
                    foreach ($rows as $r) {
                        $k = (string) ($r['str_code'] ?? '');
                        $t = $r['text'] ?? null;
                        if ($k !== '') {
                            $byStrCode[$k] = \is_string($t) ? $t : null;
                        }
                    }

                    foreach ($fieldToStrCode as $field => $strCode) {
                        $text = $byStrCode[$strCode] ?? null;
                        if (\is_string($text) && $text !== '') {
                            $entity->setResolvedTranslation($field, $text);
                        }
                    }
                }
            }
        }

        // ------------------------------------------------------------------
        // 2) Term-backed fields (#[BabelTerm]) -> resolve label_code -> STR_TR
        // ------------------------------------------------------------------
        $terms = $this->index->termsFor($class);
        if ($terms === []) {
            return;
        }

        // Collect per-field codes, and per-set aggregate
        $fieldCodes = []; // field => list<string codes>
        $wantBySet  = []; // set => list<string codes>

        foreach ($terms as $field => $meta) {
            if (!\property_exists($entity, $field)) {
                continue;
            }

            $set      = (string) ($meta['set'] ?? '');
            $multiple = (bool) ($meta['multiple'] ?? false);

            if ($set === '') {
                continue;
            }

            $raw = $entity->{$field};

            $codes = [];
            if ($multiple) {
                if (\is_array($raw)) {
                    foreach ($raw as $v) {
                        $v = \is_string($v) ? trim($v) : '';
                        if ($v !== '') {
                            $codes[] = $v;
                        }
                    }
                }
            } else {
                if (\is_string($raw) && trim($raw) !== '') {
                    $codes[] = trim($raw);
                }
            }

            if ($codes === []) {
                continue;
            }

            $codes = array_values(array_unique($codes));
            $fieldCodes[$field] = $codes;

            $wantBySet[$set] ??= [];
            $wantBySet[$set] = array_values(array_unique(array_merge($wantBySet[$set], $codes)));
        }

        if ($wantBySet === []) {
            return;
        }

        // Map set|code => label_code, and collect all label_code values
        $labelCodeBySetCode = [];
        $labelCodes = [];

        foreach ($wantBySet as $set => $codes) {
            $sql = sprintf(
                'SELECT s.%s AS set_code, t.%s AS term_code, t.%s AS label_code
                 FROM %s t
                 JOIN %s s ON s.%s = t.%s
                 WHERE s.%s = ?
                   AND t.%s IN (?)',
                BabelSchema::TERM_SET_CODE,
                BabelSchema::TERM_CODE,
                BabelSchema::TERM_LABEL_CODE,
                BabelSchema::TERM_TABLE,
                BabelSchema::TERM_SET_TABLE,
                BabelSchema::TERM_SET_ID,
                BabelSchema::TERM_TERM_SET_ID,
                BabelSchema::TERM_SET_CODE,
                BabelSchema::TERM_CODE
            );

            $rows = $conn->executeQuery(
                $sql,
                [$set, $codes],
                [ParameterType::STRING, ArrayParameterType::STRING]
            )->fetchAllAssociative();

            foreach ($rows as $r) {
                $setCode   = (string) ($r['set_code'] ?? '');
                $termCode  = (string) ($r['term_code'] ?? '');
                $labelCode = $r['label_code'] ?? null;

                if ($setCode === '' || $termCode === '' || !\is_string($labelCode) || $labelCode === '') {
                    continue;
                }

                $k = $setCode . '|' . $termCode;
                $labelCodeBySetCode[$k] = $labelCode;
                $labelCodes[] = $labelCode;
            }
        }

        if ($labelCodes === []) {
            return;
        }

        $labelCodes = array_values(array_unique($labelCodes));

        // Fetch translations for those label codes
        $sqlTr = sprintf(
            'SELECT tr.%s AS str_code, tr.%s AS text
             FROM %s tr
             WHERE tr.%s IN (?)
               AND tr.%s = ?
               AND tr.%s = ?',
            BabelSchema::STR_TR_STR_CODE,
            BabelSchema::STR_TR_TEXT,
            BabelSchema::STR_TR_TABLE,
            BabelSchema::STR_TR_STR_CODE,
            BabelSchema::STR_TR_TARGET_LOCALE,
            BabelSchema::STR_TR_ENGINE
        );

        $trRows = $conn->executeQuery(
            $sqlTr,
            [$labelCodes, $displayLocale, self::DEFAULT_ENGINE],
            [ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING]
        )->fetchAllAssociative();

        $textByLabelCode = [];
        foreach ($trRows as $r) {
            $c = (string) ($r['str_code'] ?? '');
            $t = $r['text'] ?? null;
            if ($c !== '') {
                $textByLabelCode[$c] = \is_string($t) ? $t : null;
            }
        }

        // Apply to entity runtime cache
        foreach ($fieldCodes as $field => $codes) {
            $meta = $terms[$field] ?? null;
            if (!$meta) {
                continue;
            }

            $set      = (string) ($meta['set'] ?? '');
            $multiple = (bool) ($meta['multiple'] ?? false);

            $labels = [];
            foreach ($codes as $code) {
                $k = $set . '|' . $code;
                $labelCode = $labelCodeBySetCode[$k] ?? null;
                $text = (\is_string($labelCode) && $labelCode !== '')
                    ? ($textByLabelCode[$labelCode] ?? null)
                    : null;

                $labels[] = (\is_string($text) && $text !== '') ? $text : $code;
            }

            $entity->setResolvedTerm($field, $multiple ? $labels : ($labels[0] ?? null));
        }
    }

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
