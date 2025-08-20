<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\BabelBundle\Contract\TranslatableResolvedInterface;
use Survos\BabelBundle\Entity\Traits\TranslatableHooksTrait;
use Symfony\Contracts\Translation\TranslatableInterface;

#[ORM\Entity]
class ExampleTranslatable implements TranslatableResolvedInterface
{
    use TranslatableHooksTrait;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $id;

    # Backing field for label (mapped)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $labelBacking = null;

    # Public virtual property with hooks
    #[Translatable]
    public ?string $label {
        get => $this->resolveTranslatable('label', $this->labelBacking);
        set => $this->labelBacking = $value;
    }

    # Backing for description (mapped)
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descriptionBacking = null;

    # Public virtual property with explicit context
    #[Translatable(context: 'description')]
    public ?string $description {
        get => $this->resolveTranslatable('description', $this->descriptionBacking, 'description');
        set => $this->descriptionBacking = $value;
    }
}
