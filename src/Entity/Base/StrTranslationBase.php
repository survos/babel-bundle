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

    // debatable: should this be in the base class, or in the app's concrete class?
//    #[ORM\Column(length: 64,nullable: true)]
//    public ?string $marking=null; //

    #[ORM\Id]
    #[ORM\Column(length: 8)]
    public string $locale;               // target locale

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $text=null;                 // translated text, null when untranslated

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    // the PostFlushListener creates this in raw SQL so the constructor is rarely called.
    public function __construct(string $hash, string $locale, string $text)
    {
        $this->hash      = $hash;
        $this->locale    = $locale;
        $this->text      = $text;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
