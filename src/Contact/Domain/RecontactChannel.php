<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Preferred channel to get back to a lead, picked by the admin while
 * qualifying the request.
 */
enum RecontactChannel: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.contacts.leadQuality.channel.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Phone => 'lucide:phone',
            self::Email => 'lucide:mail',
            self::Sms => 'lucide:message-square',
            self::Whatsapp => 'mdi:whatsapp',
            self::Other => 'lucide:ellipsis',
        };
    }
}
