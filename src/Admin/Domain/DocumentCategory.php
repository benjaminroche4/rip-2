<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Top-level taxonomy for the document catalogue. Used by the admin to
 * classify each piece (ID card, payslip, lease, …) and surfaced as a
 * badge on the catalogue list so admins can scan the inventory quickly.
 *
 * The string values are stable identifiers stored in the DB; human
 * labels live in the translation file (admin.tools.documents.category.<case>).
 */
enum DocumentCategory: string
{
    case IDENTITY = 'identity';
    case WORK = 'work';
    case HOUSING = 'housing';
    case FINANCIAL = 'financial';
    case EDUCATION = 'education';
    case GUARANTEE = 'guarantee';
    case OTHER = 'other';

    public function labelKey(): string
    {
        return 'admin.tools.documents.category.'.$this->value;
    }
}
