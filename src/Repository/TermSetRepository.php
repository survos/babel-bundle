<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\TermSet;

/**
 * @extends ServiceEntityRepository<TermSet>
 */
final class TermSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TermSet::class);
    }

    public function findOneByCode(string $code): ?TermSet
    {
        return $this->findOneBy(['code' => $code]);
    }
}
