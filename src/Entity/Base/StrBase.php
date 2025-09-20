<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Runtime\BabelRuntime;

#[ORM\MappedSuperclass]
abstract class StrBase
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $hash;                 // deterministic key (e.g., xxh3 of srcLocale + context + original)

    #[ORM\Column(type: Types::TEXT)]
    public string $original;             // source string (untranslated)

    #[ORM\Column(length: 8)]
    public string $srcLocale;            // e.g. 'es'

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $context = null;      // optional domain/context

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $meta = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /** Overall translation status, e.g. 'untranslated','queued','in_progress','translated','identical'. */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $status = null;

    /**
     * Per-locale status map, e.g. {"es":"queued","fr":"translated"}.
     * Use to avoid re-dispatching already-complete locales at scale.
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $localeStatuses = null;

    // ----- Property hooks (computed helpers) -----

    /** Preview of the original text (first 40 chars). */
    public string $snippet {
        get => mb_substr($this->original ?? '', 0, 40, 'UTF-8');
    }

    /** Length of the original text. */
    public int $length {
        get => mb_strlen($this->original ?? '');
    }

    public function hasTranslationFor($loc): bool {
        return $this->localeStatuses ? array_key_exists($loc, $this->localeStatuses) : false;
    }

    // the PostFlushListener creates this in raw SQL so the constructor is rarely called.
    public function __construct(string $hash, string $original, string $srcLocale, ?string $context = null)
    {
        $this->hash      = $hash;
        $this->original  = $original;
        $this->srcLocale = $srcLocale;
        $this->context   = $context;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
