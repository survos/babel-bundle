<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Fixtures\Entity;

use Survos\BabelBundle\Attribute\BabelStorage;
use Survos\BabelBundle\Attribute\StorageMode;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Traits\BabelTranslatableAttrTrait;

#[BabelStorage(StorageMode::Property)]
class SimpleOwner
{
    use BabelTranslatableAttrTrait;

    private string $label_raw = '';
    private ?string $description_raw = null;
    private ?array $i18n_raw = null;

    public function getSourceLocale(): ?string { return 'en'; }
    protected function &i18nStorage(): array { $this->i18n_raw ??= []; return $this->i18n_raw; }

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

    public function _i18n(): array { return $this->i18n_raw ?? []; }
}
