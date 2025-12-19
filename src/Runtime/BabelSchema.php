<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Runtime;

/**
 * Centralized schema names for DBAL-based commands and services.
 * Keep these aligned with Doctrine entity mappings.
 */
final class BabelSchema
{
    // Tables
    public const string STRING_TABLE = 'str';
    public const string TRANSLATION_TABLE = 'str_tr';
    public const string TERM_TABLE = 'term';
    public const string TERM_SET_TABLE = 'term_set';

    // STR columns
    public const string STR_CODE = 'code';
    public const string STR_SOURCE_LOCALE = 'source_locale';
    public const string STR_CONTEXT = 'context';

    // STR_TR columns
    public const string TR_STR_CODE = 'str_code';
    public const string TR_TARGET_LOCALE = 'target_locale';
    public const string TR_ENGINE = 'engine';
    public const string TR_TEXT = 'text';

    // TERM columns
    public const string TERM_ID = 'id';
    public const string TERM_CODE = 'code';
    public const string TERM_PATH = 'path';
    public const string TERM_LABEL_CODE = 'label_code';
    public const string TERM_SET_ID = 'term_set_id';

    // TERM_SET columns
    public const string TERM_SET_CODE = 'code';
}
