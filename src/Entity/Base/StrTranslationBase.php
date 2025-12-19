<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Base;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class StrTranslationBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'str_code', length: 64)]
    public string $strCode;

    #[ORM\Column(name: 'target_locale', length: 10)]
    public string $targetLocale;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $engine = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $text = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meta = [];
}
