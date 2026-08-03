<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for one person on the dossier detail page.
 */
final readonly class DossierPersonView
{
    public function __construct(
        public int $id,
        public DossierPersonRole $role,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public ContactLanguage $language,
        public bool $primaryContact,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
