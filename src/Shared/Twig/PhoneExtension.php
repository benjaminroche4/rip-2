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
            new TwigFilter('phone_country', [$this, 'countryCode']),
        ];
    }

    /**
     * Lowercase ISO 3166-1 alpha-2 region for an international number
     * ("+33612345678" → "fr"), or null when it can't be determined. Used to
     * pick a circle-flags icon next to phone numbers.
     */
    public function countryCode(?string $phoneNumber): ?string
    {
        if (null === $phoneNumber || '' === $phoneNumber) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($phoneNumber, null);
        } catch (NumberParseException) {
            return null;
        }

        $region = $util->getRegionCodeForNumber($parsed);

        return null !== $region ? strtolower($region) : null;
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
