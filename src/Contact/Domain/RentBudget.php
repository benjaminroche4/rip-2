<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Maximum monthly rent, charges included (call-back form). Searches start at 1 000 €,
 * so the lowest choice means 1 000 to 1 500 €. Case order is the display order.
 */
enum RentBudget: string
{
    case UpTo1500 = 'up_to_1500';
    case UpTo2500 = 'up_to_2500';
    case UpTo4000 = 'up_to_4000';
    case Over4000 = 'over_4000';

    public function labelKey(): string
    {
        return 'callback.form.rentBudget.choice.'.$this->value;
    }
}
