<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Lifecycle of a contact request in the admin, kept deliberately short:
 * new → in progress → converted or closed. A planned recall lives in the
 * dedicated recallAt field (not a status), and the way a lead was closed
 * lives in ClosureReason.
 */
enum ContactStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Converted = 'converted';
    case Closed = 'closed';

    public function labelKey(): string
    {
        return 'admin.contacts.card.status.'.$this->value;
    }

    /**
     * Tailwind classes for the status dot + label so every surface renders
     * the same color coding.
     */
    public function dotClass(): string
    {
        return match ($this) {
            self::New => 'bg-blue-500',
            self::InProgress => 'bg-amber-500',
            self::Converted => 'bg-green-600',
            self::Closed => 'bg-gray-500',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::New => 'text-blue-600',
            self::InProgress => 'text-amber-600',
            self::Converted => 'text-green-600',
            self::Closed => 'text-gray-600',
        };
    }
}
