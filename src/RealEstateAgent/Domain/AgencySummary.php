<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agencies view of the agents section — templates never
 * see the Doctrine entity, only these rows. `agentCount` is how many
 * directory agents belong to the agency; `brand` is the "enseigne" the
 * agency belongs to, if any.
 */
final readonly class AgencySummary
{
    /**
     * @param list<AgentSpecialty> $specialties
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $brand,
        public int $agentCount,
        public \DateTimeImmutable $createdAt,
        public ?string $logoFilename = null,
        public ?string $website = null,
        public array $specialties = [],
        public ?string $note = null,
        public bool $active = true,
        /** @var list<string> libellés des quartiers favoris, prêts à afficher */
        public array $areaLabels = [],
        public bool $allAreas = false,
        /** Référence publique (AY-xxxxxx), utilisée dans les URLs admin. */
        public string $reference = '',
        /** Favori d'équipe (partagé par tout le staff). */
        public bool $favorite = false,
    ) {
    }
}
