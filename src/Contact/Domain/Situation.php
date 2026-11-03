<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Visitor's situation (call-back form). Mirrors the audiences named on the site (FAQ "Pour qui").
 * Case order is the dropdown order: life stages, "other" last.
 */
enum Situation: string
{
    case Student = 'student';
    case Professional = 'professional';
    case Entrepreneur = 'entrepreneur';
    case Expat = 'expat';
    case Family = 'family';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'callback.form.situation.choice.'.$this->value;
    }
}
