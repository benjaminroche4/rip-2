<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Row of the rich agent dropdowns (visit form): identity plus the agency
 * line, never the Doctrine entity.
 */
final readonly class AgentPickerOption
{
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $avatarFilename,
        public ?string $agencyName,
        public ?string $brand,
    ) {
    }

    /** "Foncia Paris 11 · Orpi", agency alone, or null for an independent. */
    public function agencyLine(): ?string
    {
        if (null === $this->agencyName) {
            return null;
        }

        return null !== $this->brand && $this->brand !== $this->agencyName
            ? $this->agencyName.' · '.$this->brand
            : $this->agencyName;
    }
}
