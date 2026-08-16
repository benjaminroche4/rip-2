<?php

declare(strict_types=1);

namespace App\Shared\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Server-side phone normalisation for staff-entered numbers: the same E.164
 * canonical form as the public forms (client-side phone-input controller),
 * so numbers stay searchable and the flag/format filters keep working.
 */
final class PhoneNumberNormalizer
{
    /**
     * E.164 form ("+33612345678"), or null when the input is not a valid
     * phone number. Numbers without a country prefix default to France.
     */
    public static function toE164(?string $raw, string $defaultRegion = 'FR'): ?string
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($raw, $defaultRegion);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($parsed) ? $util->format($parsed, PhoneNumberFormat::E164) : null;
    }
}
