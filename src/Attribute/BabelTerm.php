<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

use Attribute;

/**
 * Marks a property as a controlled-vocabulary term (TermSet/Term) backed field.
 *
 * Minimal WIP semantics:
 * - set: TermSet.code (e.g. "category", "tag")
 * - multiple: true for arrays of term codes
 *
 * For now, the recommended storage representation is term code(s) (string|array<string>).
 * Labels/descriptions remain resolvable via Term.label_code / description_code through Str/StrTranslation.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BabelTerm
{
    public function __construct(
        public readonly string $set,
        public readonly bool $multiple = false,
        public readonly ?string $context = null,
    ) {}
}
