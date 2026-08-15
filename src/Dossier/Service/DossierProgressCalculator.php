<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierProgress;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;

/**
 * Single place deciding whether a step of a dossier is validated. Every
 * gate of the file (tabs, module cards, status advance) reads this, so a
 * rule never drifts between the screen and the server guard.
 *
 * Validation is explicit: the staff clicks "Valider" at the bottom of a
 * step card (DossierStepValidator), which stores the step on the dossier
 * and unlocks the next one. Nothing is derived from the dossier content.
 */
final readonly class DossierProgressCalculator
{
    public function forDossier(Dossier $dossier): DossierProgress
    {
        $validated = [];
        foreach (DossierStep::ordered() as $step) {
            $validated[$step->value] = $dossier->isStepValidated($step);
        }

        return DossierProgress::fromValidatedSteps($validated);
    }
}
