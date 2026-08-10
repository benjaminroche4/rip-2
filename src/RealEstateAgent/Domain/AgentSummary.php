<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Read model for the agents list — templates never see the Doctrine
 * entity, only these rows.
 */
final readonly class AgentSummary
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public ?string $agency,
        public ?string $email,
        public ?string $phone,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
