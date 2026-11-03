<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * When the visitor is available (call-back form). Moments of the day, never dated: the page is
 * cached, so "this afternoon" could not stay true. The advisor uses the next matching opening
 * hours (Monday to Friday 8am - 8pm, Saturday 8am - noon).
 */
enum ContactSlot: string
{
    case Asap = 'asap';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    public function labelKey(): string
    {
        return 'callback.form.contactSlot.choice.'.$this->value;
    }
}
