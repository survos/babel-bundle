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
final class TestOwner implements BabelHooksInterface
{
    use BabelHooksTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    // Doctrine persists the backing field; the hook property is virtual and must not be mapped.
    #[ORM\Column(name: 'label', length: 255)]
    public string $labelBacking = '';

    #[Translatable]
    public string $label {
        get => $this->resolveTranslatable('label', $this->labelBacking);
        set {
            $this->labelBacking = $value;
            $this->setResolvedTranslation('label', $value);
        }
    }
}
