<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Outcome of a visit: planned until someone closes it as done, cancelled
 * or a client no-show. Drives the row styling and the follow-up report.
 */
enum VisitStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function labelKey(): string
    {
        return 'admin.visits.status.'.$this->value;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Planned => 'bg-blue-50 text-blue-700',
            self::Done => 'bg-green-50 text-green-700',
            self::Cancelled => 'bg-gray-100 text-gray-500',
            self::NoShow => 'bg-red-50 text-red-600',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'lucide:calendar-clock',
            self::Done => 'lucide:check',
            self::Cancelled => 'lucide:x',
            self::NoShow => 'lucide:user-x',
        };
    }
}
