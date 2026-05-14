<?php

namespace App\Auth\Domain\Register;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 2 of the registration flow: password fields validated under the "account" group.
 *
 * The plain password lives only in memory during request handling; it is hashed before the
 * User entity is persisted and never written to the session storage.
 */
final class Account
{
    public function __construct(
        #[Assert\IsTrue(message: 'register.terms.required', groups: ['account'])]
        public bool $acceptTerms = false,
    ) {
    }
}
