<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Role of a person attached to a dossier. The string values are stable
 * identifiers stored in the DB; the human label comes from the translation
 * file (admin.dossiers.create.person.role.<case>).
 */
enum DossierPersonRole: string
{
    case TENANT = 'tenant';
    case FOLLOW_UP = 'follow_up';

    public function labelKey(): string
    {
        return 'admin.dossiers.create.person.role.'.$this->value;
    }
}
