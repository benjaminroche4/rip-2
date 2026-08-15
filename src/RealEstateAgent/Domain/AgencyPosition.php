<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * Job held by an agent inside their agency. Only meaningful for agency
 * agents — an independent agent has no position.
 */
enum AgencyPosition: string
{
    case Assistant = 'assistant';
    case ConsultantSale = 'consultant_sale';
    case ConsultantRental = 'consultant_rental';
    case Partner = 'partner';
    case Manager = 'manager';

    public function labelKey(): string
    {
        return 'admin.agents.position.'.$this->value;
    }
}
