<?php

namespace App\Auth\Domain\Register;

use App\Auth\Validator\Constraint\UniqueUserEmail;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 1 of the registration flow: identity fields validated under the "personal" group.
 */
final class Personal
{
    public function __construct(
        #[Assert\NotBlank(message: 'register.firstName.notBlank', groups: ['personal'])]
        #[Assert\Length(min: 2, max: 50, minMessage: 'register.firstName.minLength', maxMessage: 'register.firstName.maxLength', groups: ['personal'])]
        public ?string $firstName = null,

        #[Assert\NotBlank(message: 'register.lastName.notBlank', groups: ['personal'])]
        #[Assert\Length(min: 2, max: 50, minMessage: 'register.lastName.minLength', maxMessage: 'register.lastName.maxLength', groups: ['personal'])]
        public ?string $lastName = null,

        #[Assert\NotBlank(message: 'register.email.notBlank', groups: ['personal'])]
        #[Assert\Email(message: 'register.email.invalid', groups: ['personal'])]
        #[Assert\Length(max: 180, maxMessage: 'register.email.maxLength', groups: ['personal'])]
        #[UniqueUserEmail(groups: ['personal'])]
        public ?string $email = null,

        #[Assert\NotBlank(message: 'register.password.notBlank', groups: ['personal'])]
        #[Assert\Length(min: 8, max: 4096, minMessage: 'register.password.minLength', groups: ['personal'])]
        #[Assert\PasswordStrength(message: 'register.password.weak', groups: ['personal'])]
        #[Assert\NotCompromisedPassword(message: 'register.password.compromised', groups: ['personal'])]
        public ?string $plainPassword = null,
    ) {
    }
}
