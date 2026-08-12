<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Client's temperature after a done visit, captured in the report block.
 */
enum ClientFeeling: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function labelKey(): string
    {
        return 'admin.visits.feeling.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Hot => 'lucide:flame',
            self::Warm => 'lucide:thermometer',
            self::Cold => 'lucide:snowflake',
        };
    }
}
