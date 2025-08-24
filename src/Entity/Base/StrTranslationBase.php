<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrTranslationBase
{
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    // the PostFlushListener creates this in raw SQL so the constructor is rarely called.
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        public readonly string $hash,
        #[ORM\Id]
        #[ORM\Column(length: 8)]
        public readonly  string $locale,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $text=null)
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
