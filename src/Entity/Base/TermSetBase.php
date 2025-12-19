<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class TermSetBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 80)]
    public string $code;

    /**
     * STR code for label (translated via Babel STR/STR_TR).
     */
    #[ORM\Column(length: 64)]
    public string $labelCode;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $descriptionCode = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $rules = [];

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meta = [];

    #[ORM\Column]
    public bool $enabled = true;
}
