<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Read model for a contact form submission shown in the admin list.
 * Keeps Doctrine entities out of templates.
 */
final readonly class ContactListItem
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phoneNumber,
        public ?string $company,
        public string $helpType,
        public ?string $message,
        public \DateTimeImmutable $createdAt,
        public string $lang,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
