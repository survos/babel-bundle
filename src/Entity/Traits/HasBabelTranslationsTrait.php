<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Entity\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Optional helper: stores per-field translation keys so we can
 *  - detect source changes,
 *  - avoid recomputing hashes,
 *  - pass stable ids to other systems.
 *
 * Add "use HasBabelTranslationsTrait;" to your entity.
 */
trait HasBabelTranslationsTrait
{
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $tCodes = null;

    public function getTranslationCodes(): array
    {
        return $this->tCodes ?? [];
    }

    public function setTranslationCodes(array $codes): void
    {
        $this->tCodes = $codes ?: null;
    }
}
