<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Kind of rental guarantor the prospect can provide.
 */
enum GuarantorType: string
{
    case Physical = 'physical';
    case Garantme = 'garantme';
    case Bank = 'bank';

    public function labelKey(): string
    {
        return 'admin.contacts.project.guarantor.choice.'.$this->value;
    }
}
