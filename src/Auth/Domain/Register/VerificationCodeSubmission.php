<?php

declare(strict_types=1);

namespace App\Auth\Domain\Register;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO bound to the OTP verification form. The single `code` field is fed by the
 * 6 individual inputs in the UI (a Stimulus controller concatenates them on submit),
 * so server-side we only see a normal 6-digit string.
 */
final class VerificationCodeSubmission
{
    public function __construct(
        #[Assert\NotBlank(message: 'register.verify.code.required')]
        #[Assert\Length(exactly: 6, exactMessage: 'register.verify.code.format')]
        #[Assert\Regex(pattern: '/^\d{6}$/', message: 'register.verify.code.format')]
        public ?string $code = null,
    ) {
    }
}
