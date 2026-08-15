<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agents list — templates never see the Doctrine
 * entity, only these rows.
 */
final readonly class AgentSummary
{
    /**
     * @param list<AgentSpecialty> $specialties
     */
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public ?string $agency,
        public ?string $email,
        public ?string $phone,
        public array $specialties,
        public ?AgencyPosition $position,
        public \DateTimeImmutable $createdAt,
        public ?string $avatarFilename = null,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
