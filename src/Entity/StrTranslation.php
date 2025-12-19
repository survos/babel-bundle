<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\BabelBundle\Repository\StrTranslationRepository;

#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\Table(name: 'str_tr')]
#[ORM\UniqueConstraint(name: 'str_tr_uq', columns: ['str_code', 'target_locale', 'engine'])]
#[ORM\Index(name: 'str_tr_target_locale_idx', columns: ['target_locale'])]
#[ORM\Index(name: 'str_tr_str_code_idx', columns: ['str_code'])]
class StrTranslation extends StrTranslationBase
{
}
