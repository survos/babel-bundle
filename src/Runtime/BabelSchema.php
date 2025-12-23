<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Runtime;

final class BabelSchema
{
    public const string STR_TABLE = 'str';
    public const string STR_TR_TABLE = 'str_tr';
    public const string TERM_SET_TABLE = 'term_set';
    public const string TERM_TABLE = 'term';

    /** @deprecated Use STR_TABLE */
    public const string STRING_TABLE = self::STR_TABLE;
    /** @deprecated Use STR_TR_TABLE */
    public const string TRANSLATION_TABLE = self::STR_TR_TABLE;
    /** @deprecated Use TERM_SET_TABLE */
    public const string TERM_SETS_TABLE = self::TERM_SET_TABLE;
    /** @deprecated Use TERM_TABLE */
    public const string TERMS_TABLE = self::TERM_TABLE;

    // STR columns
    public const string STR_ID = 'id';
    public const string STR_CODE = 'code';
    public const string STR_SOURCE_LOCALE = 'source_locale';
    public const string STR_SOURCE = 'source';
    public const string STR_CONTEXT = 'context';
    public const string STR_META = 'meta';
    /** @deprecated Use STR_SOURCE_LOCALE */
    public const string STR_LOCALE = self::STR_SOURCE_LOCALE;

    // STR_TR columns
    public const string STR_TR_ID = 'id';
    public const string STR_TR_STR_CODE = 'str_code';
    public const string STR_TR_TARGET_LOCALE = 'target_locale';
    public const string STR_TR_ENGINE = 'engine';
    public const string STR_TR_TEXT = 'text';
    public const string STR_TR_META = 'meta';

    // NEW: status column (string/enum-ready)
    public const string STR_TR_STATUS = 'status';

    /** @deprecated Use STR_TR_STR_CODE */
    public const string TRANSLATION_STR_CODE = self::STR_TR_STR_CODE;
    /** @deprecated Use STR_TR_TARGET_LOCALE */
    public const string TRANSLATION_LOCALE = self::STR_TR_TARGET_LOCALE;
    /** @deprecated Use STR_TR_TEXT */
    public const string TRANSLATION_TEXT = self::STR_TR_TEXT;

    /** @deprecated Use STR_TR_TARGET_LOCALE */
    public const string TR_TARGET_LOCALE = self::STR_TR_TARGET_LOCALE;
    /** @deprecated Use STR_TR_STR_CODE */
    public const string TR_STR_CODE = self::STR_TR_STR_CODE;
    /** @deprecated Use STR_TR_TEXT */
    public const string TR_TEXT = self::STR_TR_TEXT;
    /** @deprecated Use STR_TR_ENGINE */
    public const string TR_ENGINE = self::STR_TR_ENGINE;

    // TERM_SET columns
    public const string TERM_SET_ID = 'id';
    public const string TERM_SET_CODE = 'code';
    public const string TERM_SET_LABEL_CODE = 'label_code';
    public const string TERM_SET_DESCRIPTION_CODE = 'description_code';
    public const string TERM_SET_RULES = 'rules';
    public const string TERM_SET_META = 'meta';
    public const string TERM_SET_ENABLED = 'enabled';

    // TERM columns
    public const string TERM_ID = 'id';
    public const string TERM_TERM_SET_ID = 'term_set_id';
    public const string TERM_CODE = 'code';
    public const string TERM_PATH = 'path';
    public const string TERM_LABEL_CODE = 'label_code';
    public const string TERM_DESCRIPTION_CODE = 'description_code';
    public const string TERM_RULES = 'rules';
    public const string TERM_META = 'meta';
    public const string TERM_ENABLED = 'enabled';
    public const string TERM_SORT = 'sort';
    public const string TERM_PARENT_ID = 'parent_id';
}
