<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit step validation of the dossier path: the "Valider" button at the
 * bottom of a step card calls validate(), which stores the step on the
 * dossier and thereby unlocks the next card. reopen() walks one step back.
 *
 * Both moves keep the stored list a strict prefix of DossierStep::ordered():
 * only the first step still open can be validated, only the last validated
 * one can be reopened.
 */
final readonly class DossierStepValidator
{
    public function __construct(
        private DossierProgressCalculator $calculator,
        private DossierStatusAdvancer $advancer,
        private DossierEventLogger $events,
        private EntityManagerInterface $em,
    ) {
    }

    /** Validates the step; no-op when it is not the one currently open. */
    public function validate(Dossier $dossier, DossierStep $step): bool
    {
        $progress = $this->calculator->forDossier($dossier);
        if ($progress->isValidated($step) || !$progress->isUnlocked($step)) {
            return false;
        }

        $dossier->addValidatedStep($step);
        $this->events->log($dossier, 'step_validated', ['step' => $step->labelKey()]);
        $this->em->flush();
        $this->advancer->advance($dossier);

        return true;
    }

    /**
     * Reopens a validated step; only the furthest one can be reopened, so
     * the path never holds a validated step behind an open one. The status
     * follows: it is fully derived from the validated steps (no manual
     * selector), so the advancer realigns it downwards too.
     */
    public function reopen(Dossier $dossier, DossierStep $step): bool
    {
        $progress = $this->calculator->forDossier($dossier);
        $next = $step->next();
        if (!$progress->isValidated($step) || (null !== $next && $progress->isValidated($next))) {
            return false;
        }

        $dossier->removeValidatedStep($step);
        $this->events->log($dossier, 'step_reopened', ['step' => $step->labelKey()]);
        $this->em->flush();
        $this->advancer->advance($dossier);

        return true;
    }
}
