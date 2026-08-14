<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for the dossiers list — templates never see the Doctrine
 * entities, only these rows.
 */
final readonly class DossierSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $reference,
        public ?string $primaryTenantName,
        public int $personCount,
        public \DateTimeImmutable $createdAt,
        /** Effective status (closure and pending pieces already folded in). */
        public DossierStatus $status = DossierStatus::New,
        public ?int $managerId = null,
        public ?string $managerName = null,
        public ?string $managerAvatarFilename = null,
        /** Package chosen on the source contact ("accompagne" or "confie"). */
        public ?string $offer = null,
        /** Desired move-in date from the dossier search, when filled in. */
        public ?\DateTimeImmutable $moveInAt = null,
        /** Progress between creation and move-in; null without a move-in date. */
        public ?MoveInTimeline $timeline = null,
        /** Set once the dossier is archived; drives the "Clôturé" card. */
        public ?\DateTimeImmutable $closedAt = null,
        /** Search criteria filled in: gates the modules and visit booking. */
        public bool $searchComplete = false,
    ) {
    }
}
