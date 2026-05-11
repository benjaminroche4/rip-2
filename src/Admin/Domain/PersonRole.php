<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Role of a person inside a household rental file. The string values are
 * stable identifiers stored in the DB; the human label comes from the
 * translation file (admin.tools.documents.request.person.role.<case>).
 */
enum PersonRole: string
{
    case TENANT = 'tenant';
    case GUARANTOR = 'guarantor';

    public function labelKey(): string
    {
        return 'admin.tools.documents.request.person.role.'.$this->value;
    }
}
