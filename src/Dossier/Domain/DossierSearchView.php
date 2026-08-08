<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for the dossier's search criteria ("Recherche"). Values are the
 * raw stored strings; the template maps enum-like ones (stay duration,
 * furnishing, guarantor) to their labels.
 */
final readonly class DossierSearchView
{
    public function __construct(
        public ?int $budget,
        public ?string $areas,
        public ?\DateTimeImmutable $moveInAt,
        public ?string $propertyType,
        public ?string $stayDuration,
        public ?string $furnishing,
        public ?string $guarantorType,
        public ?string $note,
    ) {
    }

}
