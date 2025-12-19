<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\TermSetBase;
use Survos\BabelBundle\Repository\TermSetRepository;

#[ORM\Entity(repositoryClass: TermSetRepository::class)]
#[ORM\Table(name: 'term_set')]
#[ORM\UniqueConstraint(name: 'term_set_code_uq', columns: ['code'])]
class TermSet extends TermSetBase
{
}
