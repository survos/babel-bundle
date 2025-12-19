<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Term;
use Survos\BabelBundle\Entity\TermSet;

/**
 * @extends ServiceEntityRepository<Term>
 */
final class TermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Term::class);
    }

    public function findOneBySetAndCode(TermSet $set, string $code): ?Term
    {
        return $this->findOneBy(['termSet' => $set, 'code' => $code]);
    }

    public function findOneBySetAndPath(TermSet $set, string $path): ?Term
    {
        return $this->findOneBy(['termSet' => $set, 'path' => $path]);
    }
}
