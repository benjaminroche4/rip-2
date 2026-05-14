<?php

namespace App\Auth\Domain\Register;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Aggregate DTO for the multi-step registration flow. Each sub-DTO is validated under the
 * group matching the step name; only the current step's group runs on each submission.
 *
 * The `$currentStep` property is updated by Symfony FormFlow as the user moves through the
 * flow. Default value bootstraps the form on the first step.
 */
final class RegisterDto
{
    public function __construct(
        #[Assert\Valid(groups: ['personal'])]
        public Personal $personal = new Personal(),

        #[Assert\Valid(groups: ['account'])]
        public Account $account = new Account(),

        public string $currentStep = 'personal',
    ) {
    }
}
