<?php

namespace App\Contact\Message;

/**
 * Carries the contact-form payload to the async handler that sends both
 * the admin notification and the client confirmation emails.
 */
final readonly class SendContactEmailsMessage
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $phoneNumber,
        public ?string $helpType,
        public ?string $message,
        public ?string $company,
        public string $lang,
        public ?string $ip,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
