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

    /** Optional per-translation progress ('queued','translated','identical',...). */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $status = null;

    // the PostFlushListener creates this in raw SQL so the constructor is rarely called.
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        public readonly string $hash,
        #[ORM\Id]
        #[ORM\Column(length: 8)]
        public readonly string $locale,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $text = null
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    // ----- Property hooks (computed helpers) -----

    /** Preview of the translated text (first 40 chars). */
    public string $snippet {
        get => mb_substr($this->text ?? '', 0, 40, 'UTF-8');
    }

    /** Length of the translated text (0 if null). */
    public int $length {
        get => mb_strlen($this->text ?? '');
    }
}
