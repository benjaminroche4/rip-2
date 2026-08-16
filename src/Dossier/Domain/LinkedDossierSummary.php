<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Lightweight read model for the "linked dossiers" banner on a lead page:
 * every dossier carrying the lead's email on one of its persons, flagged
 * when the dossier was converted from that very lead.
 */
final readonly class LinkedDossierSummary
{
    public function __construct(
        public string $reference,
        public string $name,
        public DossierPersonRole $personRole,
        public bool $closed,
        public bool $fromThisContact,
    ) {
    }
}
