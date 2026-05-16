<?php

declare(strict_types=1);

namespace App\Auth\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Property-level constraint that fails when the given email already belongs to
 * a persisted User. Lives on the Personal step 1 DTO so the registration flow
 * surfaces the conflict immediately — never letting the user fill step 2 with
 * an address that we already know is taken.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueUserEmail extends Constraint
{
    public string $message = 'register.email.alreadyUsed';
}
