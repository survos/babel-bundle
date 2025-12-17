<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class TranslateStringEvent extends Event
{
    public function __construct(
        public string $hash,
        public string $original,
        public string $sourceLocale,
        public string $targetLocale,
        public ?string $translated = null, // listener sets this
    ) {
//        assert(false, "If we use this, it should be moved to contracts.  Otherwise, remove it.");
    }
}
