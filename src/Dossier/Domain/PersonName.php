<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Dossier\Entity\DossierPerson;

/** Display name of a dossier person, tolerant to half-filled rows. */
final class PersonName
{
    private function __construct()
    {
    }

    /** "First Last" form, used by the audit trail and the recipients list. */
    public static function firstLast(?DossierPerson $person): string
    {
        return trim(trim((string) $person?->getFirstName()).' '.trim((string) $person?->getLastName()));
    }

    /** "Last First" form, used by the tenant rows of the file module. */
    public static function lastFirst(?DossierPerson $person): string
    {
        return trim(trim((string) $person?->getLastName()).' '.trim((string) $person?->getFirstName()));
    }
}
