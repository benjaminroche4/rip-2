<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agencies view of the agents section — templates never
 * see the Doctrine entity, only these rows. `agentCount` is how many
 * directory agents belong to the agency.
 */
final readonly class AgencySummary
{
    public function __construct(
        public int $id,
        public string $name,
        public int $agentCount,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
