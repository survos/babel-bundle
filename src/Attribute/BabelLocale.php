<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Attribute;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Marks a property as the **source locale** for the record.
 * Also acts as a Symfony Validator Constraint for optional format validation.
 *
 * format:
 *  - 'bcp47'           : looser check via \Locale::canonicalize (if intl enabled), fallback regex
 *  - 'language'        : 'en', 'es', 'fr'
 *  - 'region'          : 'US', 'MX', 'FR'
 *  - 'language_region' : 'en-US', 'es_MX'
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BabelLocale extends Constraint
{
    public function __construct(
        public string $format = 'bcp47',
        public bool $allowNull = true,
        public string $message = 'This value is not a valid locale.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }

    public function validatedBy(): string
    {
        return \Survos\BabelBundle\Validator\BabelLocaleValidator::class;
    }
}
