<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\PersonName;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Repository\DossierEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Review side of the file module: status changes, refusals (with the
 * notification email) and the piece's public description. Audit trail and
 * status advancement included; live re-render events stay in the component.
 */
final class DossierDocumentReviewer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly DossierDocumentRefusalMailer $refusalMailer,
        private readonly DossierDocumentCompletionMailer $completionMailer,
        private readonly DossierEventLogger $events,
        private readonly DossierEventRepository $eventRepository,
        private readonly DossierStatusAdvancer $advancer,
    ) {
    }

    /**
     * Applies a non-refusal review status (requested, received, validated).
     * receivedAt keeps tracking the deposit date: stamped when the piece
     * becomes received, cleared when it goes back to requested.
     */
    public function applyStatus(Dossier $dossier, DossierDocument $document, DossierDocumentStatus $status): void
    {
        $becomesValidated = DossierDocumentStatus::Validated === $status
            && DossierDocumentStatus::Validated !== $document->getStatus();
        $document->setStatus($status);
        $document->setRefusalReason(null);
        if (DossierDocumentStatus::Requested === $status) {
            $document->setReceivedAt(null);
            // Repasser en "Demandée" = nouveau cycle (ex. fiche de paie du
            // mois suivant) : la demande est redatée, le badge d'ancienneté
            // repart de zéro, les fichiers déjà déposés restent en historique.
            $document->setRequestedAt($this->clock->now());
        } elseif (null === $document->getReceivedAt()) {
            $document->setReceivedAt($this->clock->now());
        }
        $this->events->log($dossier, DossierDocumentStatus::Validated === $status ? 'document_validated' : 'document_status', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => PersonName::firstLast($document->getPerson()),
            'status' => $status->labelKey(),
        ]);
        $this->em->flush();
        $this->advancer->advance($dossier);

        if ($becomesValidated) {
            $this->notifyCompletionWhenJustReached($dossier, $document);
        }
    }

    /**
     * Tells the tenant the file is complete when this validation is the one
     * that completed it. Fires only on the transition (the last pending
     * piece just flipped to validated) and once per request cycle: a
     * validated piece bounced to received and validated again stays silent,
     * a documents_requested newer than the last documents_completed opens a
     * new cycle and re-arms the email. A mailer failure never breaks the
     * validation, and nothing is logged when no email left.
     */
    private function notifyCompletionWhenJustReached(Dossier $dossier, DossierDocument $document): void
    {
        if ($dossier->hasPendingDocuments() || !$this->hasDocuments($dossier) || !$this->startsNewCompletionCycle($dossier)) {
            return;
        }

        try {
            if (!$this->completionMailer->send($dossier, $document)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        // The completion email embeds the pairing code: a successful send
        // re-arms its sliding 90-day expiry window on the deposit page.
        $dossier->setPairingCodeSentAt($this->clock->now());
        $this->events->log($dossier, 'documents_completed', [
            'tenant' => PersonName::firstLast($document->getPerson()),
        ]);
        $this->em->flush();
    }

    private function hasDocuments(Dossier $dossier): bool
    {
        foreach ($dossier->getPersons() as $person) {
            if (!$person->getDocuments()->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A completion email already covered this request cycle unless a new
     * documents_requested was logged after the latest documents_completed.
     */
    private function startsNewCompletionCycle(Dossier $dossier): bool
    {
        $completed = $this->eventRepository->findLatestOfKind((int) $dossier->getId(), 'documents_completed');
        if (null === $completed) {
            return true;
        }

        $requested = $this->eventRepository->findLatestOfKind((int) $dossier->getId(), 'documents_requested');

        return null !== $requested && $this->isAfter($requested, $completed);
    }

    private function isAfter(DossierEvent $event, DossierEvent $reference): bool
    {
        return $event->getCreatedAt() > $reference->getCreatedAt()
            || ($event->getCreatedAt() == $reference->getCreatedAt() && $event->getId() > $reference->getId());
    }

    /**
     * Refuses the piece: persists the optional reason (truncated), flips the
     * status and emails the tenant so they deposit a new version.
     */
    public function refuse(Dossier $dossier, DossierDocument $document, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        $document->setStatus(DossierDocumentStatus::Refused);
        $document->setRefusalReason('' !== $reason ? $reason : null);
        if (null === $document->getReceivedAt()) {
            $document->setReceivedAt($this->clock->now());
        }
        $this->events->log($dossier, 'document_refused', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => PersonName::firstLast($document->getPerson()),
            'reason' => $reason,
        ]);
        $this->em->flush();
        $this->advancer->advance($dossier);

        if ($this->refusalMailer->send($dossier, $document)) {
            // The refusal email embeds the pairing code: a successful send
            // re-arms its sliding 90-day expiry window on the deposit page.
            $dossier->setPairingCodeSentAt($this->clock->now());
            $this->em->flush();
        }
    }

    /** Saves the piece's public description ('' clears it, truncated at 500). */
    public function updateDescription(Dossier $dossier, DossierDocument $document, string $description): void
    {
        $description = mb_substr(trim($description), 0, 500);
        $document->setDescription('' !== $description ? $description : null);
        $this->events->log($dossier, 'description_updated', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => PersonName::firstLast($document->getPerson()),
            'description' => $description,
        ]);
        $this->em->flush();
        $this->advancer->advance($dossier);
    }
}
