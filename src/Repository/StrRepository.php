<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Str;

/**
 * @extends ServiceEntityRepository<Str>
 */
final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Str::class);
    }
}
