<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * Stable identifier for this source string (your Babel hash/code).
     */
    #[ORM\Column(name: 'code', length: 64)]
    public string $code;

    #[ORM\Column(name: 'source_locale', length: 10)]
    public string $sourceLocale;

    #[ORM\Column(type: Types::TEXT)]
    public string $source;

    #[ORM\Column(name: 'context', length: 80, nullable: true)]
    public ?string $context = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meta = [];
}
