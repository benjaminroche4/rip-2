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
        public ?string $managerName = null,
        public ?string $managerAvatarFilename = null,
        /** Package chosen on the source contact ("accompagne" or "confie"). */
        public ?string $offer = null,
    ) {
    }
}
