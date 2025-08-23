<?php
declare(strict_types=1);

namespace Survos\BabelBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use ReflectionClass;
use Survos\BabelBundle\Attribute\Translatable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * String-backed flow:
 * - Detects changed #[Translatable] string columns during onFlush
 * - After flush (postFlush), upserts into str and str_translation via DBAL
 *
 * Canonical schema (public props expected):
 *   str:             hash (PK), original, src_locale
 *   str_translation: (hash, locale) PK, text NULLable, status?, updated_at?
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class StringBackedTranslatableFlushSubscriber
{
    /** @var array<string, array{original:string, src:?string}> keyed by hash */
    private array $pending = [];

    public function __construct(
        #[Autowire(param: 'kernel.default_locale')] private readonly string $defaultLocale = 'en',
        /** @var string[] */
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabledLocales = [],
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $entities = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates()
        );

        foreach ($entities as $entity) {
            $rc = new ReflectionClass($entity);

            foreach ($rc->getProperties() as $rp) {
                $attrs = $rp->getAttributes(Translatable::class);
                if (!$attrs) {
                    continue;
                }

                $rp->setAccessible(true);
                $original = $rp->getValue($entity);

                if (!\is_string($original) || $original === '') {
                    continue; // nothing to index
                }

                // Hash by content to dedupe; choose any stable algo you prefer
                $hash = sha1($original);

                // Prefer entity-provided srcLocale if present; else default
                $src = null;
                if (property_exists($entity, 'srcLocale')) {
                    $src = \is_string($entity->srcLocale) && $entity->srcLocale !== ''
                        ? $entity->srcLocale
                        : null;
                }

                $this->pending[$hash] = [
                    'original' => $original,
                    'src'      => $src ?? $this->defaultLocale,
                ];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $em   = $args->getObjectManager();
        $conn = $em->getConnection();
        $plat = $conn->getDatabasePlatform()->getName();

        // defaults if framework.enabled_locales not set
        $locales = $this->enabledLocales !== [] ? $this->enabledLocales : [$this->defaultLocale];

        // Upsert STR
        $sqlStr = match ($plat) {
            'postgresql' => "INSERT INTO str (hash, original, src_locale)
                             VALUES (:hash, :original, :src)
                             ON CONFLICT (hash) DO UPDATE
                               SET original = EXCLUDED.original, src_locale = EXCLUDED.src_locale",
            'sqlite'     => "INSERT INTO str (hash, original, src_locale)
                             VALUES (:hash, :original, :src)
                             ON CONFLICT(hash) DO UPDATE SET
                               original = excluded.original, src_locale = excluded.src_locale",
            default      => "INSERT INTO str (hash, original, src_locale)
                             VALUES (:hash, :original, :src)
                             ON DUPLICATE KEY UPDATE
                               original = VALUES(original), src_locale = VALUES(src_locale)",
        };

        // Upsert STR_TRANSLATION (ensure row exists, keep text NULL)
        $statusSet = ", status = 'untranslated'";
        $nowCol = $plat === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';

        $sqlTr = match ($plat) {
            'postgresql' => "INSERT INTO str_translation (hash, locale, text, updated_at, status)
                             VALUES (:hash, :locale, NULL, {$nowCol}, 'untranslated')
                             ON CONFLICT (hash, locale) DO NOTHING",
            'sqlite'     => "INSERT INTO str_translation (hash, locale, text, updated_at, status)
                             VALUES (:hash, :locale, NULL, {$nowCol}, 'untranslated')
                             ON CONFLICT(hash, locale) DO NOTHING",
            default      => "INSERT INTO str_translation (hash, locale, text, updated_at, status)
                             VALUES (:hash, :locale, NULL, {$nowCol}, 'untranslated')
                             ON DUPLICATE KEY UPDATE text = text", // no-op update
        };

        $conn->beginTransaction();
        try {
            foreach ($this->pending as $hash => $row) {
                $conn->executeStatement($sqlStr, [
                    'hash'     => $hash,
                    'original' => $row['original'],
                    'src'      => $row['src'],
                ]);

                foreach ($locales as $loc) {
                    $conn->executeStatement($sqlTr, [
                        'hash'   => $hash,
                        'locale' => (string) $loc,
                    ]);
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            // do not rethrow to avoid breaking user transaction; log instead
            // You can inject a LoggerInterface if you want to log here
        } finally {
            $this->pending = [];
        }
    }
}
