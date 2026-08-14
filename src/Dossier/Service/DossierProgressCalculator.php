<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierProgress;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use App\Visit\Repository\VisitRepository;

/**
 * Single place deciding whether a step of a dossier is validated. Every
 * gate of the file (tabs, module cards, status advance) reads this, so a
 * rule never drifts between the screen and the server guard.
 */
final readonly class DossierProgressCalculator
{
    public function __construct(private VisitRepository $visits)
    {
    }

    public function forDossier(Dossier $dossier): DossierProgress
    {
        return DossierProgress::fromValidatedSteps([
            DossierStep::Persons->value => $this->personsValidated($dossier),
            DossierStep::Search->value => $dossier->getSearch()?->isComplete() ?? false,
            DossierStep::File->value => $this->fileValidated($dossier),
            DossierStep::Visit->value => $this->visitValidated($dossier),
            // Terminal step: nothing comes after it to unlock.
            DossierStep::Payment->value => false,
        ]);
    }

    /** At least one tenant, and exactly one of them flagged primary. */
    private function personsValidated(Dossier $dossier): bool
    {
        $primaries = 0;
        $tenants = 0;
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT !== $person->getRole()) {
                continue;
            }
            ++$tenants;
            if ($person->isPrimaryContact()) {
                ++$primaries;
            }
        }

        return $tenants > 0 && 1 === $primaries;
    }

    /**
     * Pieces requested and all validated. A dossier nobody ever asked
     * anything from is not "done": hasPendingDocuments() alone would say
     * the opposite, since it only knows about pieces that exist.
     */
    private function fileValidated(Dossier $dossier): bool
    {
        $documents = 0;
        foreach ($dossier->getPersons() as $person) {
            $documents += $person->getDocuments()->count();
        }

        return $documents > 0 && !$dossier->hasPendingDocuments();
    }

    private function visitValidated(Dossier $dossier): bool
    {
        $id = $dossier->getId();

        return null !== $id && $this->visits->countDoneByDossier((int) $id) > 0;
    }
}
