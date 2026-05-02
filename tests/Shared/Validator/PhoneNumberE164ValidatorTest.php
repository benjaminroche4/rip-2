<?php

namespace App\Tests\Shared\Validator;

use App\Shared\Validator\PhoneNumberE164;
use App\Shared\Validator\PhoneNumberE164Validator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<PhoneNumberE164Validator>
 */
final class PhoneNumberE164ValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): PhoneNumberE164Validator
    {
        return new PhoneNumberE164Validator();
    }

    public function testNullAndEmptyAreSkipped(): void
    {
        $this->validator->validate(null, new PhoneNumberE164());
        $this->validator->validate('', new PhoneNumberE164());
        $this->assertNoViolation();
    }

    public function testValidE164Passes(): void
    {
        $this->validator->validate('+33612345678', new PhoneNumberE164());
        $this->assertNoViolation();
    }

    public function testValueWithoutPlusFails(): void
    {
        $this->validator->validate('33612345678', new PhoneNumberE164());
        $this->buildViolation('contact.contactForm.phoneNumber.invalidFormat')->assertRaised();
    }

    public function testNonCanonicalE164Fails(): void
    {
        // Spaces / formatting are not canonical E.164.
        $this->validator->validate('+33 6 12 34 56 78', new PhoneNumberE164());
        $this->buildViolation('contact.contactForm.phoneNumber.invalidFormat')->assertRaised();
    }

    public function testInvalidNumberFails(): void
    {
        $this->validator->validate('+12345', new PhoneNumberE164());
        $this->buildViolation('contact.contactForm.phoneNumber.invalidNumber')->assertRaised();
    }
}
