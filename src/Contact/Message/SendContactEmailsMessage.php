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
        public ?string $offer = null,
        /**
         * Call-back form only (null on the contact form): answers as field => enum value,
         * e.g. ['situation' => 'family', 'contactSlot' => 'morning']. Selects the dedicated emails.
         *
         * @var array<string, string>|null
         */
        public ?array $callbackAnswers = null,
    ) {
    }
}
