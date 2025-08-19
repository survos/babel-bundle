<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrBase
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $hash;                 // deterministic key (e.g., xxh3 of srcLocale + context + original)

    #[ORM\Column(type: 'text')]
    public string $original;             // source string (untranslated)

    #[ORM\Column(length: 8)]
    public string $srcLocale;            // e.g. 'es'

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $context = null;      // optional domain/context

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

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
