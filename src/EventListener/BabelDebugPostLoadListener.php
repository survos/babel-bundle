<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Debug\BabelDebugRecorderInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

#[AsDoctrineListener(event: Events::postLoad)]
final class BabelDebugPostLoadListener
{
    public function __construct(
        private readonly BabelDebugRecorderInterface $recorder,
        private readonly Connection $connection,
        private readonly TranslatableIndex $index,
        private readonly ?LocaleContext $localeContext = null,
        private readonly ?RequestStack $requestStack = null,    ) {
    }

    public function __invoke(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $refl = new \ReflectionObject($entity);

        if ($refl->getAttributes(BabelStorage::class) === []) {
            return;
        }

        $id = null;
        if (\method_exists($entity, 'getId')) {
            try {
                $id = $entity->getId();
            } catch (\Throwable) {
                $id = null;
            }
        }

        $request = $this->requestStack?->getCurrentRequest();
        $requestLocale = $request?->getLocale();
        $defaultLocale = $request?->getDefaultLocale();

        $babelLocale = null;
        $babelLocaleSource = null;
        try {
            $babelLocale = $this->localeContext?->getLocale();
            $babelLocaleSource = 'LocaleContext::getLocale()';
        } catch (\Throwable) {
            $babelLocale = null;
            $babelLocaleSource = null;
        }

        // This is the locale Babel *should* be using in most apps.
        $effectiveLocale = $babelLocale ?: $requestLocale ?: $defaultLocale;

        // Try to discover a per-entity str-code map (common pattern).
        $codeMap = $this->extractStrCodeMap($entity);

        $fields = [];
        $total = 0;
        $appliedCount = 0;
        $missingCount = 0;
        $hitCount = 0;

        foreach ($refl->getProperties() as $prop) {
            if ($prop->getAttributes(Translatable::class) === []) {
                continue;
            }
            $total++;

            $name = $prop->getName();

            $value = $this->readProperty($entity, $prop);
            $backing = null;

            $backingPropName = $name.'Backing';
            if ($refl->hasProperty($backingPropName)) {
                try {
                    $bp = $refl->getProperty($backingPropName);
                    $backing = $entity->{$bp->getName()};
                } catch (\Throwable) {
                    $backing = null;
                }
            }

            $applied = null;
            if (\is_scalar($value) || $value === null) {
                $applied = ($value !== $backing);
                if ($applied) {
                    $appliedCount++;
                }
            }

            $srcLocale = HashUtil::normalizeLocale($this->resolveSourceLocaleForEntity($entity, $refl->getName()));
            if ($srcLocale === '') {
                $srcLocale = HashUtil::normalizeLocale($defaultLocale ?: 'en');
            }

            $strHash = null;
            $hasRow = null;
            $translatedText = null;

            if (\is_string($backing) && $backing !== '') {
                $strHash = HashUtil::calcSourceKey($backing, $srcLocale);

                if ($effectiveLocale) {
                    [$hasRow, $translatedText] = $this->lookupTranslationByStrHash($strHash, (string) $effectiveLocale);
                    if ($hasRow) {
                        $hitCount++;
                    } else {
                        $missingCount++;
                    }
                } else {
                    $missingCount++;
                }
            } else {
                $missingCount++;
            }

            $fields[] = [
                'name' => $name,
                'value' => $value,
                'backing' => $backing,
                'applied' => $applied,
                'src_locale' => $srcLocale,
                'str_hash' => $strHash,
                'target_locale' => $effectiveLocale,
                'has_translation' => $hasRow,
                'translated_text' => $translatedText,
            ];

        }

        $this->recorder->record('doctrine.postLoad', [
            'class' => $refl->getName(),
            'id' => $id,
            'request_locale' => $requestLocale,
            'default_locale' => $defaultLocale,
            'babel_locale' => $babelLocale,
            'babel_locale_source' => $babelLocaleSource,
            'effective_locale' => $effectiveLocale,
            'field_count' => \count($fields),
            'summary' => [
                'total' => $total,
                'applied' => $appliedCount,
                'hits' => $hitCount,
                'missing' => $missingCount,
            ],
            'fields' => $fields,
        ]);
    }

    private function readProperty(object $entity, \ReflectionProperty $prop): mixed
    {
        try {
            if ($prop->isPublic()) {
                return $prop->getValue($entity);
            }
            $prop->setAccessible(true);
            return $prop->getValue($entity);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSourceLocaleForEntity(object $entity, string $class): string
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

        return $this->localeContext?->getDefault() ?? '';
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function lookupTranslationByStrHash(string $strHash, string $locale): array
    {
        $sql = <<<'SQL'
SELECT t.text
FROM str_translation t
WHERE t.str_hash = :sh AND t.locale = :loc
LIMIT 1
SQL;

        try {
            $text = $this->connection->fetchOne($sql, ['sh' => $strHash, 'loc' => $locale]);
            if ($text === false) {
                return [false, null];
            }
            return [true, \is_string($text) ? $text : null];
        } catch (\Throwable) {
            return [false, null];
        }
    }


    /**
     * Best-effort extraction of a str-code map from common conventions:
     *  - private array $str_code_map
     *  - private array $strCodeMap
     *
     * @return array<string,string>
     */
    private function extractStrCodeMap(object $entity): array
    {
        $refl = new \ReflectionObject($entity);
        foreach (['str_code_map', 'strCodeMap'] as $propName) {
            if (!$refl->hasProperty($propName)) {
                continue;
            }
            try {
                $p = $refl->getProperty($propName);
                $p->setAccessible(true);
                $v = $p->getValue($entity);
                if (\is_array($v)) {
                    /** @var array<string,string> $v */
                    return $v;
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        return [];
    }

    private function guessCodeProperty(object $entity, string $field): ?string
    {
        $refl = new \ReflectionObject($entity);
        foreach ([$field.'Code', $field.'StrCode', $field.'_code'] as $propName) {
            if (!$refl->hasProperty($propName)) {
                continue;
            }
            try {
                $p = $refl->getProperty($propName);
                $p->setAccessible(true);
                $v = $p->getValue($entity);
                return \is_string($v) && $v !== '' ? $v : null;
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function lookupTranslation(string $strCode, string $locale): array
    {
        // Adjust column names if yours differ; these are the typical ones used in your bundle.
        $sql = <<<'SQL'
SELECT t.text
FROM str_translation t
WHERE t.str_code = :code AND t.locale = :locale
LIMIT 1
SQL;

        try {
            $text = $this->connection->fetchOne($sql, ['code' => $strCode, 'locale' => $locale]);
            if ($text === false) {
                return [false, null];
            }
            return [true, \is_string($text) ? $text : null];
        } catch (\Throwable) {
            return [false, null];
        }
    }
}
