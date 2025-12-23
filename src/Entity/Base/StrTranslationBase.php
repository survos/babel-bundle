<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrTranslationBase
{
    public const string STATUS_NEW = 'new';
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_TRANSLATED = 'translated';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_STALE = 'stale';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'str_code', length: 64)]
    public string $strCode;

    #[ORM\Column(name: 'target_locale', length: 10)]
    public string $targetLocale;

    /**
     * Provider engine that produced the current translation (libre, deepl, ...).
     * This is NOT a stub marker.
     */
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $engine = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $text = null;

    /**
     * Translation workflow status (enum-ready).
     * Default "new" means stub exists but not yet translated.
     */
    #[ORM\Column(length: 16, options: ['default' => self::STATUS_NEW])]
    public string $status = self::STATUS_NEW;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    /**
     * Metadata for the translation row.
     *
     * DB-level default avoids INSERT OR IGNORE silently discarding rows on SQLite
     * when meta is NOT NULL but has no DEFAULT.
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $meta = [];
}
