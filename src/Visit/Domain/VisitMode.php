<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * How the client attends: on site, or remotely (the agent holds the video
 * call from the property — common while the client is still abroad).
 */
enum VisitMode: string
{
    case InPerson = 'in_person';
    case Remote = 'remote';

    public function labelKey(): string
    {
        return 'admin.visits.mode.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::InPerson => 'lucide:footprints',
            self::Remote => 'lucide:video',
        };
    }
}
