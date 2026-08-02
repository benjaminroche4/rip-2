<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Language used to reach out to a person of the dossier. Mirrors the
 * locales the site supports (fr/en).
 */
enum ContactLanguage: string
{
    case FR = 'fr';
    case EN = 'en';

    public function labelKey(): string
    {
        return 'admin.dossiers.create.person.language.'.$this->value;
    }
}
