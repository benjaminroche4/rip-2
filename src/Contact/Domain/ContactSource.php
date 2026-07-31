<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * How the request reached us. Website submissions are stamped "form" at
 * insert time; manual admin entries pick from the other channels.
 */
enum ContactSource: string
{
    case Form = 'form';
    case Phone = 'phone';
    case Email = 'email';
    case Website = 'website';
    case Whatsapp = 'whatsapp';

    /**
     * @return list<self>
     */
    public static function manualCases(): array
    {
        return [self::Phone, self::Email, self::Website, self::Whatsapp];
    }

    public function labelKey(): string
    {
        return 'admin.contacts.contactSource.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Form => 'lucide:file-text',
            self::Phone => 'lucide:phone',
            self::Email => 'lucide:mail',
            self::Website => 'lucide:globe',
            self::Whatsapp => 'mdi:whatsapp',
        };
    }
}
