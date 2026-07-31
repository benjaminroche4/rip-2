<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Why a submission ended up "unqualified" or "closed" — filled by the
 * admin when closing, to learn where leads are lost.
 */
enum ClosureReason: string
{
    case BudgetUnrealistic = 'budget_unrealistic';
    case Unreachable = 'unreachable';
    case ProfileMismatch = 'profile_mismatch';
    case SolvedAlone = 'solved_alone';
    case OutOfArea = 'out_of_area';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.contacts.closure.'.$this->value;
    }
}
