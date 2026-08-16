<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agency detail page.
 */
final readonly class AgencyDetail
{
    /**
     * @param list<AgentSpecialty> $specialties
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $brand,
        public ?string $address,
        public ?string $phone,
        public ?string $email,
        public \DateTimeImmutable $createdAt,
        public ?string $logoFilename = null,
        public ?string $website = null,
        public array $specialties = [],
        public ?string $note = null,
        public bool $active = true,
        public ?string $areas = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
    }
}
