<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrBase;
use Survos\BabelBundle\Repository\StrRepository;

#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Table(name: 'str')]
#[ORM\UniqueConstraint(name: 'str_code_uq', columns: ['code'])]
#[ORM\Index(name: 'str_ctx_idx', columns: ['context'])]
#[ORM\Index(name: 'str_src_locale_idx', columns: ['source_locale'])]
#[ORM\Index(name: 'str_src_locale_ctx_idx', columns: ['source_locale', 'context'])]
class Str extends StrBase
{
}
