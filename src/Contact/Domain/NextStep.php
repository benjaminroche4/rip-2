<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Predefined next action on an in-progress request. One-click chips on the
 * detail page; kept as a closed list so the pipeline stays comparable.
 */
enum NextStep: string
{
    case Call = 'call';
    case VideoCall = 'video_call';
    case Visit = 'visit';
    case Proposal = 'proposal';
    case AwaitingClient = 'awaiting_client';
    case PrepareFile = 'prepare_file';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.contacts.nextStep.choice.'.$this->value;
    }
}
