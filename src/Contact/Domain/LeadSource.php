<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * How the lead found the agency — filled by the admin while qualifying,
 * feeds future acquisition KPIs.
 */
enum LeadSource: string
{
    case Website = 'website';
    case Google = 'google';
    case Recommendation = 'recommendation';
    case Instagram = 'instagram';
    case Linkedin = 'linkedin';
    case Partner = 'partner';
    case ReturningClient = 'returning_client';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'admin.contacts.source.'.$this->value;
    }
}
