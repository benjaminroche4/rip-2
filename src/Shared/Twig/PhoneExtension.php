<?php

namespace App\Shared\Twig;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class PhoneExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('phone_format', [$this, 'format']),
        ];
    }

    public function format(?string $phoneNumber, string $format = 'international'): string
    {
        if (null === $phoneNumber || '' === $phoneNumber) {
            return '';
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($phoneNumber, null);
        } catch (NumberParseException) {
            return $phoneNumber;
        }

        if (!$util->isValidNumber($parsed)) {
            return $phoneNumber;
        }

        $target = match ($format) {
            'national' => PhoneNumberFormat::NATIONAL,
            'e164' => PhoneNumberFormat::E164,
            'rfc3966' => PhoneNumberFormat::RFC3966,
            default => PhoneNumberFormat::INTERNATIONAL,
        };

        return $util->format($parsed, $target);
    }
}
