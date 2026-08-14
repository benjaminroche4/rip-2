<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves the dossier status forward as steps get validated (search complete
 * -> "Recherche en cours", visit carried out -> "Bien trouvé").
 *
 * Only ever forward: an operator who picked a further status by hand keeps
 * it, and nothing here can walk a dossier backwards. Closed dossiers are
 * left alone entirely.
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

        $reached = $this->progress->forDossier($dossier)->reachedStatus();
        if (null === $reached || self::rank($reached) <= self::rank($current)) {
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

    /** Position in the manual pipeline; unknown statuses stay at the back. */
    private static function rank(DossierStatus $status): int
    {
        $index = array_search($status, DossierStatus::manualCases(), true);

        return false === $index ? -1 : $index;
    }
}
