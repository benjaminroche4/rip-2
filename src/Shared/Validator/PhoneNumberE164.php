<?php

namespace App\Shared\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PhoneNumberE164 extends Constraint
{
    public string $invalidFormatMessage = 'contact.contactForm.phoneNumber.invalidFormat';
    public string $invalidNumberMessage = 'contact.contactForm.phoneNumber.invalidNumber';
}
