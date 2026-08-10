<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Predefined next action on an in-progress request. One-click chips on the
 * detail page; kept as a closed list so the pipeline stays comparable.
 */
enum NextStep: string
{
    case Recontact = 'recontact';
    case Visio = 'visio';
    case QuotePreparation = 'quote_preparation';
    case QuoteSent = 'quote_sent';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.contacts.nextStep.choice.'.$this->value;
    }

    /**
     * Steps that need a date: when to call back, when the visio happens,
     * when to follow up on a sent quote.
     */
    public function needsDate(): bool
    {
        return \in_array($this, [self::Recontact, self::Visio, self::QuoteSent], true);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Recontact => 'lucide:phone',
            self::Visio => 'lucide:video',
            self::QuotePreparation => 'lucide:file-pen-line',
            self::QuoteSent => 'lucide:send',
            self::Other => 'lucide:ellipsis',
        };
    }
}
