<?php

namespace App\Auth\Domain\Register;

use App\Auth\Domain\Situation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 2 of the registration flow: contact + consent fields validated under the
 * "account" group.
 *
 * The plain password lives only in memory during request handling; it is hashed
 * before the User entity is persisted and never written to the session storage.
 *
 * Consent to the terms of use & privacy policy is captured implicitly by the act
 * of submitting the form — surfaced as a disclaimer below the submit button —
 * so no `acceptTerms` checkbox lives here.
 */
final class Account
{
    public function __construct(
        #[Assert\NotBlank(message: 'register.phoneNumber.notBlank', groups: ['account'])]
        #[Assert\Length(max: 25, maxMessage: 'register.phoneNumber.maxLength', groups: ['account'])]
        public ?string $phoneNumber = null,

        #[Assert\NotBlank(message: 'register.nationality.notBlank', groups: ['account'])]
        #[Assert\Country(message: 'register.nationality.invalid', groups: ['account'])]
        public ?string $nationality = null,

        #[Assert\NotNull(message: 'register.situation.required', groups: ['account'])]
        public ?Situation $situation = null,
    ) {
    }
}
