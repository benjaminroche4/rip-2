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
        public ?string $profession = null,
        public ?int $monthlyIncome = null,
        public ?\DateTimeImmutable $birthDate = null,
        public ?string $nationality = null,
        public bool $noProfession = false,
        public ?string $employer = null,
        public ?string $jobTitle = null,
        public ?\DateTimeImmutable $contractStartDate = null,
        public ?\DateTimeImmutable $contractEndDate = null,
        public bool $trialPeriodOver = false,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
