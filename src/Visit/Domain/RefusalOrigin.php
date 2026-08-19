<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Qui a refusé le bien quand le retour client est "Refuse" : le bailleur
 * (propriétaire ou agence), le client lui-même, ou une autre raison. Seul
 * le refus DU CLIENT alimente le compteur de biens refusés du dossier.
 */
enum RefusalOrigin: string
{
    case Landlord = 'landlord';
    case Client = 'client';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.visits.refusalOrigin.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Landlord => 'lucide:building-2',
            self::Client => 'lucide:user',
            self::Other => 'lucide:circle-help',
        };
    }
}
