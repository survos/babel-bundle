<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrTranslationBase
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $hash;                 // FK to StrBase::hash (logical)

    #[ORM\Id]
    #[ORM\Column(length: 8)]
    public string $locale;               // target locale

    #[ORM\Column(type: 'text')]
    public string $text;                 // translated text

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $hash, string $locale, string $text)
    {
        $this->hash      = $hash;
        $this->locale    = $locale;
        $this->text      = $text;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
