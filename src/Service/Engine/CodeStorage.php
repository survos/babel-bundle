<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service\Engine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Contracts\CodeStringCarrier;

/**
 * Code-mode storage: carriers expose a stable code → original map.
 * Creates source rows in `str` and source `str_translation`,
 * and inserts placeholder rows for other enabled locales (text='').
 */
final class CodeStorage implements StringStorage
{
    /**
     * @param array<int,string> $enabledLocales Typically %kernel.enabled_locales%
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly array $enabledLocales = [],
    ) {}

    public function populate(object $carrier, ?string $emName = null): int
    {
        if (!$carrier instanceof CodeStringCarrier) {
            return 0;
        }

        $conn = $this->conn($emName);
        $src  = \method_exists($carrier, 'getSourceLocale') && \is_string($carrier->getSourceLocale())
            ? $carrier->getSourceLocale()
            : 'en';

        $n = 0;
        foreach ($carrier->getStringCodeMap() as $field => $code) {
            $original = $carrier->getOriginalFor($field);
            if (!\is_string($original) || $original === '') {
                continue;
            }

            $this->upsertStr($conn, $code, $original, $src);
            $this->upsertTrSource($conn, $code, $src, $original);

            // Placeholders so babel:translate can find blanks
            foreach ($this->enabledLocales as $loc) {
                if (!\is_string($loc) || $loc === '' || $loc === $src) {
                    continue;
                }
                $this->ensureTrPlaceholder($conn, $code, $loc);
            }

            $n++;
        }

        return $n;
    }

    /* ---------------------------- helpers ---------------------------- */

    private function conn(?string $emName): Connection
    {
        $em = $this->registry->getManager($emName);
        return $em->getConnection();
    }

    private function upsertStr(Connection $conn, string $hash, string $original, string $src): void
    {
        $now = 'CURRENT_TIMESTAMP';

        $sqlUpdate = 'UPDATE str
                      SET original = :original, src_locale = :src, updated_at = '.$now.'
                      WHERE hash = :hash';
        $updated = $conn->executeStatement($sqlUpdate, [
            'original' => $original, 'src' => $src, 'hash' => $hash,
        ]);

        if ($updated > 0) return;

        try {
            $sqlInsert = 'INSERT INTO str (hash, original, src_locale, created_at, updated_at)
                          VALUES (:hash, :original, :src, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, [
                'hash' => $hash, 'original' => $original, 'src' => $src,
            ]);
        } catch (\Throwable) {
            // race-safe retry
            $conn->executeStatement($sqlUpdate, [
                'original' => $original, 'src' => $src, 'hash' => $hash,
            ]);
        }
    }

    private function upsertTrSource(Connection $conn, string $hash, string $srcLocale, string $text): void
    {
        $now = 'CURRENT_TIMESTAMP';

        $sqlUpdate = 'UPDATE str_translation
                      SET text = :text, updated_at = '.$now.'
                      WHERE hash = :hash AND locale = :loc';
        $updated = $conn->executeStatement($sqlUpdate, [
            'text' => $text, 'hash' => $hash, 'loc' => $srcLocale,
        ]);

        if ($updated > 0) return;

        try {
            $sqlInsert = 'INSERT INTO str_translation (hash, locale, text, created_at, updated_at)
                          VALUES (:hash, :loc, :text, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, [
                'hash' => $hash, 'loc' => $srcLocale, 'text' => $text,
            ]);
        } catch (\Throwable) {
            $conn->executeStatement($sqlUpdate, [
                'text' => $text, 'hash' => $hash, 'loc' => $srcLocale,
            ]);
        }
    }

    private function ensureTrPlaceholder(Connection $conn, string $hash, string $locale): void
    {
        $now = 'CURRENT_TIMESTAMP';
        try {
            $sqlInsert = 'INSERT INTO str_translation (hash, locale, text, created_at, updated_at)
                          VALUES (:hash, :loc, :text, '.$now.', '.$now.')';
            $conn->executeStatement($sqlInsert, [
                'hash' => $hash, 'loc' => $locale, 'text' => '',
            ]);
        } catch (\Throwable) {
            // already exists; noop
        }
    }
}
