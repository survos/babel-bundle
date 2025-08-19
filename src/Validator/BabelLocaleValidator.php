<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Validator;

use Survos\BabelBundle\Attribute\BabelLocale;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class BabelLocaleValidator extends ConstraintValidator
{
    /** @param string|null $value */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof BabelLocale) {
            return;
        }

        if ($value === null || $value === '') {
            if ($constraint->allowNull) return;
            $this->context->buildViolation($constraint->message)->addViolation();
            return;
        }

        $s = (string) $value;
        $ok = false;

        switch ($constraint->format) {
            case 'language':        // 'en' or 'eng'
                $ok = (bool) preg_match('/^[a-z]{2,3}$/', $s);
                break;

            case 'region':          // 'US'
                $ok = (bool) preg_match('/^[A-Z]{2}$/', $s);
                break;

            case 'language_region': // 'en-US' or 'es_MX'
                $ok = (bool) preg_match('/^[a-z]{2,3}[-_][A-Z]{2}$/', $s);
                break;

            case 'bcp47':
            default:
                if (\class_exists(\Locale::class)) {
                    $canon = \Locale::canonicalize(str_replace('_', '-', $s));
                    $ok = is_string($canon) && $canon !== '';
                } else {
                    // relaxed fallback: language or language-REGION
                    $ok = (bool) preg_match('/^[a-z]{2,3}([_-][A-Z]{2})?$/', $s);
                }
                break;
        }

        if (!$ok) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $s)
                ->addViolation();
        }
    }
}
