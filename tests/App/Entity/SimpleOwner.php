<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\App\Entity;

use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

final class SimpleOwner implements BabelHooksInterface
{
    use BabelHooksTrait;

    // New convention required by BabelHooksTrait::getBackingValue("<field>")
    public string $labelBacking = '';
    public ?string $descriptionBacking = null;

    #[Translatable]
    public string $label {
        get => $this->resolveTranslatable('label', $this->labelBacking);
        set {
            $this->labelBacking = $value;
            // avoid stale cache when backing changes
            $this->setResolvedTranslation('label', $value);
        }
    }

    #[Translatable]
    public ?string $description {
        get => $this->resolveTranslatable('description', $this->descriptionBacking);
        set {
            $this->descriptionBacking = $value;
            if ($value !== null) {
                $this->setResolvedTranslation('description', $value);
            }
        }
    }
}
