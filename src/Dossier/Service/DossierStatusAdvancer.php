<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the dossier status aligned with the step path: the status names the
 * first step still pending ("Personnes", "Recherche", ...), and switches to
 * "Finalisation" once the visit step is validated (apartment found). Works
 * in both directions: reopening a step walks the status back too. The
 * status has no manual selector, the step path is its single source of
 * truth. Closed dossiers are left alone entirely.
 */
final readonly class DossierStatusAdvancer
{
    public function __construct(
        private DossierProgressCalculator $progress,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Aligns the stored status with the steps walked so far. Returns the
     * status it settled on, and flushes only when something changed.
     */
    public function advance(Dossier $dossier): DossierStatus
    {
        $current = $dossier->getStatus();
        if ($dossier->isClosed()) {
            return $current;
        }

        // Le statut nomme l'étape en attente : première étape non validée,
        // ou "Finalisation" une fois la visite faite (bien trouvé).
        $reached = DossierStatus::fromPendingStep($this->progress->forDossier($dossier)->currentStep());
        if ($reached === $current) {
            return $current;
        }

        $dossier->setStatus($reached);
        // Same audit trail as a manual change, so the follow-up thread shows
        // who/what moved the dossier even when nobody clicked the selector.
        $this->em->persist((new DossierEvent())
            ->setDossier($dossier)
            ->setKind('status_changed')
            ->setPayload([
                'from' => $current->value,
                'to' => $reached->value,
                'auto' => true,
            ]));
        $this->em->flush();

        return $reached;
    }
}
