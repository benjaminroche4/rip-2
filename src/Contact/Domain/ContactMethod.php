<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * How the visitor prefers to be called back: a WhatsApp call or a regular phone call (always a call).
 * Case order is the display order: WhatsApp first.
 */
enum ContactMethod: string
{
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';

    public function labelKey(): string
    {
        return 'callback.form.contactMethod.choice.'.$this->value;
    }
}
