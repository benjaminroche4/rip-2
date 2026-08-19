<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Client's decision about the visited property, captured in the "Retour
 * client" block of the report card. Independent from the feeling: a warm
 * client can still decline the property.
 */
enum ClientDecision: string
{
    case Thinking = 'thinking';
    case Positioning = 'positioning';
    case Refused = 'refused';

    public function labelKey(): string
    {
        return 'admin.visits.decision.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Thinking => 'lucide:hourglass',
            self::Positioning => 'lucide:hand',
            self::Refused => 'lucide:x',
        };
    }
}
