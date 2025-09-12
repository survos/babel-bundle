<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Runtime\BabelRuntime;

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

    // overwrite to set marking, etc.
    public function init(): void
    {

    }

    // the PostFlushListener creates this in raw SQL so the constructor is rarely called.
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40, unique: true)]
        public readonly string $hash, // strHash + targetLocale

        #[ORM\Column(length: 32)]
        public readonly string $strHash, // same as str->hash

        #[ORM\Column(length: 8)]
        public readonly string $locale,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $text = null
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->init();
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
