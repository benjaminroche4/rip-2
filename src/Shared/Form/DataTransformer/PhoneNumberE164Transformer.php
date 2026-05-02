<?php

namespace App\Shared\Form\DataTransformer;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * @implements DataTransformerInterface<string|null, string|null>
 */
final class PhoneNumberE164Transformer implements DataTransformerInterface
{
    public function __construct(
        private readonly string $defaultRegion = 'FR',
    ) {
    }

    public function transform(mixed $value): ?string
    {
        return $value;
    }

    public function reverseTransform(mixed $value): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse((string) $value, $this->defaultRegion);
        } catch (NumberParseException) {
            $failure = new TransformationFailedException('Invalid phone number');
            $failure->setInvalidMessage('contact.contactForm.phoneNumber.invalidFormat');

            throw $failure;
        }

        if (!$util->isValidNumber($parsed)) {
            $failure = new TransformationFailedException('Invalid phone number');
            $failure->setInvalidMessage('contact.contactForm.phoneNumber.invalidNumber');

            throw $failure;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
