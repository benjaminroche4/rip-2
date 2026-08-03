<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for the "responsable de dossier": the assigned staff member on
 * the detail page and the assignable choices in the picker.
 */
final readonly class DossierManagerView
{
    public function __construct(
        public int $id,
        public string $fullName,
        public string $email,
        public ?string $avatarFilename,
    ) {
    }
}
