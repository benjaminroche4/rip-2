<?php

namespace App\Shared\Form\DataTransformer;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\DataTransformerInterface;

/**
 * Best-effort canonicalisation to E.164. Never blocks the form: if the input
 * cannot be parsed or is "invalid" by libphonenumber's strict definition, we
 * fall back to a digits-only string with the leading + preserved. Losing a
 * lead because the user mistyped one digit is worse than storing a slightly
 * malformed number an admin can clean later.
 *
 * @implements DataTransformerInterface<string|null, string|null>
 */
final class PhoneNumberE164Transformer implements DataTransformerInterface
{
    private const MAX_LENGTH = 25;

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

        $raw = (string) $value;
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($raw, $this->defaultRegion);
            if ($util->isValidNumber($parsed)) {
                return $util->format($parsed, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // fall through to lenient cleanup
        }

        return $this->lenientFallback($raw);
    }

    private function lenientFallback(string $raw): ?string
    {
        $hasPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ('' === $digits) {
            return null;
        }

        $cleaned = ($hasPlus ? '+' : '').$digits;

        return substr($cleaned, 0, self::MAX_LENGTH);
    }
}
