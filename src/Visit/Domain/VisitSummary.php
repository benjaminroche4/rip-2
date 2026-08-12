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
        public bool $clientPresent = true,
        public ?string $report = null,
        public ?ClientFeeling $clientFeeling = null,
    ) {
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
