<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Str;

final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Str::class); }

    public function upsertOriginal(string $code, string $original, string $srcLocale): Str
    {
        $em  = $this->getEntityManager();
        $str = $this->find($code);
        if (!$str) { $str = new Str($code, $original, $srcLocale); $em->persist($str); return $str; }
        if ($str->original !== $original || $str->srcLocale !== $srcLocale) {
            $str->srcLocale = $srcLocale; $str->original = $original;
            $t = $str->t; $t[$srcLocale] ??= $original; $str->t = $t;
        }
        return $str;
    }

    /** @param list<array{0:string,1:string,2:string}> $items */
    public function upsertMany(array $items): int
    {
        $n=0; foreach ($items as [$code,$original,$src]) { $this->upsertOriginal($code,$original,$src); $n++; } return $n;
    }
}
