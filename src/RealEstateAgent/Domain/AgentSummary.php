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
        public ?int $agencyId,
        public ?string $email,
        public ?string $phone,
        public array $specialties,
        public ?AgencyPosition $position,
        public \DateTimeImmutable $createdAt,
        public ?string $avatarFilename = null,
        public ?string $note = null,
        public bool $active = true,
        public ?\DateTimeImmutable $updatedAt = null,
        public bool $favorite = false,
        /** Logo de l'agence (clé objet), affiché dans le chip agence de la card. */
        public ?string $agencyLogo = null,
        /** Références publiques (AG-/AY-), utilisées dans les URLs admin. */
        public string $reference = '',
        public ?string $agencyReference = null,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
