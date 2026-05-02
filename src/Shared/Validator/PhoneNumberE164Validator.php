<?php

namespace App\Shared\Validator;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PhoneNumberE164Validator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PhoneNumberE164) {
            throw new UnexpectedTypeException($constraint, PhoneNumberE164::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!str_starts_with($value, '+')) {
            $this->context->buildViolation($constraint->invalidFormatMessage)->addViolation();

            return;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($value, null);
        } catch (NumberParseException) {
            $this->context->buildViolation($constraint->invalidFormatMessage)->addViolation();

            return;
        }

        if (!$util->isValidNumber($parsed)) {
            $this->context->buildViolation($constraint->invalidNumberMessage)->addViolation();

            return;
        }

        $canonical = $util->format($parsed, PhoneNumberFormat::E164);
        if ($canonical !== $value) {
            $this->context->buildViolation($constraint->invalidFormatMessage)->addViolation();
        }
    }
}
