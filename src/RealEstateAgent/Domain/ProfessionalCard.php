<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Cartes professionnelles obligatoires pour exercer l'immobilier en France
 * (loi Hoguet). An agent (or their agency) can hold several.
 */
enum ProfessionalCard: string
{
    case Transaction = 't';
    case Gestion = 'g';
    case Syndic = 's';

    public function labelKey(): string
    {
        return 'admin.agents.professionalCard.choice.'.$this->value;
    }

    /** Nature de la carte ("transaction", "gestion", "syndic"), affichée en retrait du nom. */
    public function hintKey(): string
    {
        return 'admin.agents.professionalCard.hint.'.$this->value;
    }
}
