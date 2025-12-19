<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\TermBase;
use Survos\BabelBundle\Repository\TermRepository;

#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'term')]
#[ORM\UniqueConstraint(name: 'term_term_set_code_uq', columns: ['term_set_id', 'code'])]
#[ORM\UniqueConstraint(name: 'term_term_set_path_uq', columns: ['term_set_id', 'path'])]
#[ORM\Index(name: 'term_term_set_path_idx', columns: ['term_set_id', 'path'])]
class Term extends TermBase
{
    #[ORM\ManyToOne(targetEntity: TermSet::class)]
    #[ORM\JoinColumn(name: 'term_set_id', nullable: false, onDelete: 'CASCADE')]
    public TermSet $termSet;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    public ?self $parent = null;
}
