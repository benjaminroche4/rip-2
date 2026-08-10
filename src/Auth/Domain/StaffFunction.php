<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Business function of a staff member (not a security role): used to filter
 * assignment dropdowns in the back-office, e.g. only search agents can be
 * picked to follow a dossier search.
 */
enum StaffFunction: string
{
    case SearchAgent = 'search_agent';
    case VisitAgent = 'visit_agent';
    case Closer = 'closer';

    public function labelKey(): string
    {
        return 'admin.users.functions.'.$this->value.'.label';
    }

    public function descriptionKey(): string
    {
        return 'admin.users.functions.'.$this->value.'.description';
    }
}
