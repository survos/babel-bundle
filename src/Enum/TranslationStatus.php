<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Enum;

enum TranslationStatus: string
{
    case UNTRANSLATED = 'untranslated';
    case MACHINE      = 'machine';
    case HUMAN        = 'human';
    case REVIEWED     = 'reviewed';
}
