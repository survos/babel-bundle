<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;

final class StrTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, StrTranslation::class); }

    public function upsertTranslation(string $code, string $locale, string $text, ?bool $approved = null): void
    {
        $em = $this->getEntityManager();
        $tr = $this->find(['code'=>$code,'locale'=>$locale]);
        if (!$tr) { $tr = new StrTranslation($code,$locale,$text,$approved ?? false); $em->persist($tr); }
        else { if ($approved !== null) $tr->approved = $approved; $tr->text = $text; $tr->updated_at = new \DateTimeImmutable(); }

        $str = $em->getRepository(Str::class)->find($code);
        if ($str) { $t = $str->t; $t[$locale] = $text; $str->t = $t; }
    }
}
