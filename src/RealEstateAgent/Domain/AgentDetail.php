<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agent detail page.
 */
final readonly class AgentDetail
{
    /**
     * @param list<AgentSpecialty>    $specialties
     * @param list<ProfessionalCard>  $professionalCards
     */
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public ?int $agencyId,
        public ?string $agencyName,
        public ?string $email,
        public ?string $phone,
        public array $specialties,
        public ?AgencyPosition $position,
        public \DateTimeImmutable $createdAt,
        public ?string $avatarFilename = null,
        public ?string $note = null,
        public bool $active = true,
        public ?string $createdByName = null,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $updatedByName = null,
        public array $professionalCards = [],
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
