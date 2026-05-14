<?php

namespace App\Auth\Domain\Register;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 2 of the registration flow: contact + consent fields validated under the
 * "account" group.
 *
 * The plain password lives only in memory during request handling; it is hashed
 * before the User entity is persisted and never written to the session storage.
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

        #[Assert\IsTrue(message: 'register.terms.required', groups: ['account'])]
        public bool $acceptTerms = false,
    ) {
    }
}
