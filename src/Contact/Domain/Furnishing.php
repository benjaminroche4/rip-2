<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Furnished or unfurnished home wanted (call-back form).
 */
enum Furnishing: string
{
    case Furnished = 'furnished';
    case Unfurnished = 'unfurnished';
    case Either = 'either';

    public function labelKey(): string
    {
        return 'callback.form.furnishing.choice.'.$this->value;
    }
}
