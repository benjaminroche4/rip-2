<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Where a dossier stands in the step path: which steps are validated, which
 * are reachable, and which one the staff should work on now.
 *
 * A step is reachable when every step before it is validated. Validation of
 * a step never depends on the steps after it, so the answer is stable
 * whatever the operator clicks.
 */
final readonly class DossierProgress
{
    /**
     * @param array<string, bool> $validated keyed by DossierStep value
     */
    private function __construct(private array $validated)
    {
    }

    /**
     * @param array<string, bool> $validated keyed by DossierStep value
     */
    public static function fromValidatedSteps(array $validated): self
    {
        $complete = [];
        foreach (DossierStep::ordered() as $step) {
            $complete[$step->value] = $validated[$step->value] ?? false;
        }

        return new self($complete);
    }

    public function isValidated(DossierStep $step): bool
    {
        return $this->validated[$step->value];
    }

    /** Every previous step validated: the tab opens. */
    public function isUnlocked(DossierStep $step): bool
    {
        foreach (DossierStep::ordered() as $candidate) {
            if ($candidate === $step) {
                return true;
            }
            if (!$this->isValidated($candidate)) {
                return false;
            }
        }

        return true;
    }

    /** Step blocking this one, i.e. the one to validate to get in. */
    public function blockedBy(DossierStep $step): ?DossierStep
    {
        foreach (DossierStep::ordered() as $candidate) {
            if ($candidate === $step) {
                return null;
            }
            if (!$this->isValidated($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** First step left to validate; null once the path is walked. */
    public function currentStep(): ?DossierStep
    {
        foreach (DossierStep::ordered() as $step) {
            if (!$this->isValidated($step)) {
                return $step;
            }
        }

        return null;
    }

    public function validatedCount(): int
    {
        return \count(array_filter($this->validated));
    }
}
