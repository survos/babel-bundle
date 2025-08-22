<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Repository\StrTranslationRepository;

#[ORM\MappedSuperclass]
//#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_str_tr_code_locale', columns: ['code', 'locale'])]
class StrTranslation
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    public string $code;

    #[ORM\Id]
    #[ORM\Column(length: 10)]
    public string $locale;

    #[ORM\Column(type: Types::TEXT)]
    public string $text;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $approved = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updated_at;

    public function __construct(string $code, string $locale, string $text, bool $approved = false)
    {
        $this->code = $code;
        $this->locale = $locale;
        $this->text = $text;
        $this->approved = $approved;
        $this->updated_at = new \DateTimeImmutable();
    }
}
