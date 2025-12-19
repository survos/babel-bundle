<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class TermBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * Stable identifier within TermSet; not locale-dependent.
     */
    #[ORM\Column(length: 120)]
    public string $code;

    /**
     * Hierarchical path, e.g. "crime/robberies". For flat sets, path can equal code or a slug.
     * Keep as VARCHAR for predictable indexing.
     */
    #[ORM\Column(length: 512)]
    public string $path;

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

    #[ORM\Column(nullable: true)]
    public ?int $sort = null;
}
