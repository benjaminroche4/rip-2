<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Furnished or unfurnished rental, on the housing project card.
 */
enum Furnishing: string
{
    case Furnished = 'furnished';
    case Unfurnished = 'unfurnished';

    public function labelKey(): string
    {
        return 'admin.contacts.project.furnishing.choice.'.$this->value;
    }
}
