<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Lease type of the visited property, captured on the visit form
 * ("Le bien en détail" section). Purely descriptive, never blocking.
 */
enum LeaseType: string
{
    case Airbnb = 'airbnb';
    case Mobility = 'mobility';
    case CivilCode = 'civil_code';
    case Alur = 'alur';

    public function labelKey(): string
    {
        return 'admin.visits.create.propertyDetails.leaseType.'.$this->value;
    }
}
