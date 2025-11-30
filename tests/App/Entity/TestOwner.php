<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

#[ORM\Entity]
#[BabelStorage(StorageMode::Property)]
class TestOwner implements BabelHooksInterface
{
    use BabelHooksTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    // Backing fields (source-locale)
    #[ORM\Column(type: 'text')]
    private string $label_raw = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description_raw = null;

    // Per-locale blob storage
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $i18n_raw = null;

    public function getSourceLocale(): ?string { return 'en'; }
    protected function &i18nStorage(): array { $this->i18n_raw ??= []; return $this->i18n_raw; }

    // Public properties with hooks
    #[Translatable]
    public string $label {
        get => $this->label_raw;
        set => $this->label_raw = $value;
    }

    #[Translatable]
    public ?string $description {
        get => $this->description_raw;
        set => $this->description_raw = $value;
    }

    // Expose i18n for asserts (test-only helper)
    public function _i18n(): array { return $this->i18n_raw ?? []; }
}
