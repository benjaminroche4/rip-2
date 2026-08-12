<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Nature of a property visit: each one prepares differently and shows a
 * distinct icon across the visits pages.
 */
enum VisitType: string
{
    case PropertyVisit = 'property_visit';
    case Inventory = 'inventory';
    case TechnicalIntervention = 'technical_intervention';

    public function labelKey(): string
    {
        return 'admin.visits.type.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::PropertyVisit => 'lucide:door-open',
            self::Inventory => 'lucide:clipboard-list',
            self::TechnicalIntervention => 'lucide:wrench',
        };
    }
}
