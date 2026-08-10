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
        public \DateTimeImmutable $scheduledAt,
        public string $address,
        public ?float $latitude,
        public ?float $longitude,
        public string $dossierName,
        public string $dossierReference,
        public ?string $agentName,
    ) {
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
