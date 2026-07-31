<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * How long the prospect intends to stay. Chips on the housing project card,
 * with the month ranges spelled out so everyone reads the same thing.
 */
enum StayDuration: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';

    public function labelKey(): string
    {
        return 'admin.contacts.project.stayDuration.choice.'.$this->value;
    }

    public function hintKey(): string
    {
        return 'admin.contacts.project.stayDuration.hint.'.$this->value;
    }
}
