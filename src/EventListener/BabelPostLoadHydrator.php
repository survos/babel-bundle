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

/**
 * PostLoad hydrator for string-backed translations.
 *
 * Looks up TR rows by (str_code, target_locale).
 * IMPORTANT: If displayLocale === srcLocale, use the original/backing text and never query STR_TR.
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

        $srcLocale = HashUtil::normalizeLocale($this->resolveSourceLocale($entity, $class));
        $displayLocale = HashUtil::normalizeLocale($this->locale->get());

        // If we're displaying the source locale, short-circuit: use backing/original values.
        if ($displayLocale !== '' && $srcLocale !== '' && $displayLocale === $srcLocale) {
            foreach ($fields as $field) {
                $original = $entity->getBackingValue($field);
                if (\is_string($original) && $original !== '') {
                    $entity->setResolvedTranslation($field, $original);
                }
            }
            return;
        }

        // Compute canonical STR codes per field from backing + source locale
        $fieldToStrCode = [];
        foreach ($fields as $field) {
            $original = $entity->getBackingValue($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            // IMPORTANT: this must match however STR.code is computed in your onFlush subscriber.
            $strCode = HashUtil::calcSourceKey($original, $srcLocale);
            $fieldToStrCode[$field] = $strCode;
        }

        if ($fieldToStrCode === []) {
            return;
        }

        $conn = $em->getConnection();

        // Fetch translations for the display locale by (str_code, target_locale)
        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s IN (?) AND %s = ?',
            BabelSchema::TR_STR_CODE,
            BabelSchema::TR_TEXT,
            BabelSchema::TRANSLATION_TABLE,
            BabelSchema::TR_STR_CODE,
            BabelSchema::TR_TARGET_LOCALE
        );

        $rows = $conn->executeQuery(
            $sql,
            [\array_values($fieldToStrCode), $displayLocale],
            [ArrayParameterType::STRING, ParameterType::STRING]
        )->fetchAllAssociative();

        $byStrCode = [];
        foreach ($rows as $r) {
            $k = (string) ($r[BabelSchema::TR_STR_CODE] ?? '');
            $t = $r[BabelSchema::TR_TEXT] ?? null;
            if ($k !== '') {
                $byStrCode[$k] = \is_string($t) ? $t : null;
            }
        }

        foreach ($fieldToStrCode as $field => $strCode) {
            if (!\array_key_exists($strCode, $byStrCode)) {
                // Normal for new strings / before ensure/push/pull.
                $this->logger->debug('Babel hydration: no STR_TR row found for (str_code, target_locale)', [
                    'class'         => $class,
                    'field'         => $field,
                    'str_code'      => $strCode,
                    'target_locale' => $displayLocale,
                    'src_locale'    => $srcLocale,
                ]);
                continue;
            }

            $text = $byStrCode[$strCode];
            if (!\is_string($text) || $text === '') {
                // Translation exists but not filled yet; allow fallback to backing text.
                continue;
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
