<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Read model for the visits page — templates never see the Doctrine
 * entities, only these rows.
 */
final readonly class VisitSummary
{
    public function __construct(
        public int $id,
        public string $reference,
        public \DateTimeImmutable $scheduledAt,
        public string $address,
        public ?float $latitude,
        public ?float $longitude,
        public string $dossierName,
        public string $dossierReference,
        public ?string $agentName,
        public ?int $assigneeId = null,
        public ?string $assigneeName = null,
        public ?string $assigneeAvatar = null,
        public ?string $bookedByName = null,
        public ?string $bookedByAvatar = null,
        public ?string $note = null,
        public VisitType $type = VisitType::PropertyVisit,
        public VisitStatus $status = VisitStatus::Planned,
        public ?string $listingUrl = null,
        public int $durationMinutes = 30,
        /** Id du dossier, pour distinguer un vrai double-booking dans le bandeau doublon. */
        public ?int $dossierId = null,
        public bool $clientPresent = false,
        public ?string $report = null,
        /** Note destinée au client, rédigée ou générée par IA. */
        public ?string $clientNote = null,
        /** Dernier envoi de la note client par email (null = jamais envoyée). */
        public ?\DateTimeImmutable $clientNoteSentAt = null,
        public ?ClientFeeling $clientFeeling = null,
        /** Première photo du bien, vignette de la card de liste. */
        public ?int $firstPhotoId = null,
        /** Fiche annuaire de l'agent immobilier (avatar, agence, lien). */
        public ?string $agentAvatar = null,
        /** "Foncia Paris 11 · Orpi", agence seule, ou null (indépendant). */
        public ?string $agentAgencyLine = null,
        public ?string $agentReference = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Miroir Google Calendar posé (événement central créé). */
        public bool $calendarSynced = false,
        /** Instantané du dernier modificateur (fiche), null = jamais retouchée. */
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $updatedByName = null,
        public ?string $updatedByAvatar = null,
        /** Retour client sur le bien (réfléchit / se positionne / refuse). */
        public ?ClientDecision $clientDecision = null,
        public ?\DateTimeImmutable $clientDecisionAt = null,
        /** Issue de la candidature déposée; null = en attente. */
        public ?ApplicationOutcome $applicationOutcome = null,
        /** Échéance de réflexion (date seule), posée quand le client réfléchit. */
        public ?\DateTimeImmutable $decisionDeadline = null,
        /** Origine du refus (bailleur ou client) quand la décision est "Refuse". */
        public ?RefusalOrigin $refusalOrigin = null,
    ) {
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
