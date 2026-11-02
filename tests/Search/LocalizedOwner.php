<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Search;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Traits\BabelHooksTrait;

#[ORM\Entity]
#[ORM\Table(name: 'localized_owner')]
final class LocalizedOwner implements BabelHooksInterface
{
    use BabelHooksTrait;

    #[ORM\Id]
    #[ORM\Column]
    public string $code;

    #[ORM\Column(name: 'label_backing', nullable: true)]
    public ?string $labelBacking = null;

    #[ORM\Column]
    public string $category;

    #[ORM\Column(name: 'source_locale', nullable: true)]
    public ?string $sourceLocale = null;

    public ?string $label {
        get => $this->resolveTranslatable('label', $this->labelBacking);
    }
}
