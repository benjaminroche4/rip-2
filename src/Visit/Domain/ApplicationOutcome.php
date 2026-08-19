<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Sort réservé au dossier déposé sur le bien (décision du bailleur, du
 * propriétaire ou de l'agence), quand le client s'est positionné. L'état
 * neutre (null) = candidature en attente.
 */
enum ApplicationOutcome: string
{
    case Accepted = 'accepted';
    case Refused = 'refused';

    public function labelKey(): string
    {
        return 'admin.visits.applicationOutcome.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Accepted => 'lucide:check',
            self::Refused => 'lucide:x',
        };
    }
}
