<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Repository\StrRepository;

#[ORM\MappedSuperclass]
//#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Index(name: 'idx_str_original_hash', columns: ['original_hash'])]
class Str implements \Stringable
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    public string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $original_raw;

    #[ORM\Column(length: 10)]
    private string $src_locale_raw = 'en';

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $t_raw = null;

    #[ORM\Column(length: 64)]
    public string $original_hash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $created_at;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updated_at;

    public function __construct(string $code, string $original, string $srcLocale = 'en')
    {
        $this->code = $code;
        $this->original_raw = $original;
        $this->src_locale_raw = $srcLocale;
        $this->original_hash = hash('sha256', $srcLocale."\0".$original);
        $this->created_at = new \DateTimeImmutable();
        $this->updated_at = $this->created_at;
        $this->t_raw = [$srcLocale => $original];
    }

    private function touch(): void { $this->updated_at = new \DateTimeImmutable(); }

    public string $original {
        get => $this->original_raw;
        set {
            $this->original_raw = $value;
            $this->original_hash = hash('sha256', $this->src_locale_raw."\0".$value);
            $this->touch();
        }
    }

    public string $srcLocale {
        get => $this->src_locale_raw;
        set { $this->src_locale_raw = $value; $this->touch(); }
    }

    public array $t {
        get => $this->t_raw ?? [];
        set { $this->t_raw = (array)$value ?: null; $this->touch(); }
    }

    public function __toString(): string
    {
        return substr($this->code, 0, 8) . ':' . mb_substr($this->original, 0, 80);
    }
}
