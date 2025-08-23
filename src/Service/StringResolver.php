<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\Persistence\ManagerRegistry;

/**
 * Read-only helpers for Str / StrTranslation using DBAL + metadata.
 */
final class StringResolver
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    /**
     * @return array<string, array{original:string, src:string, context:?string}>
     */
    public function getStrRowsByHashes(array $hashes, ?string $emName = null): array
    {
        if ($hashes === []) return [];

        $em   = $this->registry->getManager($emName);
        $conn = $em->getConnection();

        $strMeta  = $em->getClassMetadata(\App\Entity\Str::class);
        $table    = $strMeta->getTableName();
        $colHash  = $strMeta->getColumnName('hash');
        $colOrig  = $strMeta->getColumnName('original');
        $colSrc   = $strMeta->getColumnName('srcLocale');
        $colCtx   = $strMeta->hasField('context') ? $strMeta->getColumnName('context') : null;

        $sql = sprintf(
            'SELECT %s AS hash, %s AS original, %s AS src, %s AS ctx FROM %s WHERE %s IN (?)',
            $colHash, $colOrig, $colSrc, $colCtx ? $colCtx : 'NULL', $table, $colHash
        );
        $stmt = $conn->executeQuery($sql, [$hashes], [\Doctrine\DBAL\ArrayParameterType::STRING]);

        $rows = [];
        while ($r = $stmt->fetchAssociative()) {
            $rows[(string)$r['hash']] = [
                'original' => (string)$r['original'],
                'src'      => (string)$r['src'],
                'context'  => $r['ctx'] !== null ? (string)$r['ctx'] : null,
            ];
        }
        return $rows;
    }

    /**
     * @return array<string, array<string,string|null>> map[hash][locale] = text|null
     */
    public function getTranslationsForHashes(array $hashes, ?string $emName = null): array
    {
        if ($hashes === []) return [];

        $em   = $this->registry->getManager($emName);
        $conn = $em->getConnection();

        $trMeta  = $em->getClassMetadata(\App\Entity\StrTranslation::class);
        $table   = $trMeta->getTableName();
        $colHash = $trMeta->getColumnName('hash');
        $colLoc  = $trMeta->getColumnName('locale');
        $colText = $trMeta->getColumnName('text');

        $sql = sprintf(
            'SELECT %s AS hash, %s AS locale, %s AS text FROM %s WHERE %s IN (?)',
            $colHash, $colLoc, $colText, $table, $colHash
        );

        $stmt = $conn->executeQuery($sql, [$hashes], [\Doctrine\DBAL\ArrayParameterType::STRING]);

        $out = [];
        while ($r = $stmt->fetchAssociative()) {
            $h = (string)$r['hash'];
            $l = (string)$r['locale'];
            $t = $r['text']; // may be NULL (nullable text)
            $out[$h][$l] = \is_string($t) ? $t : null;
        }
        return $out;
    }
}
