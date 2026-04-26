<?php

namespace App\PropertyEstimation\Message;

/**
 * Carries the property-estimation lead payload to the async email handler.
 */
final readonly class SendEstimationEmailMessage
{
    public function __construct(
        public ?string $address,
        public ?string $propertyCondition,
        public ?int $surface,
        public ?int $bathroom,
        public ?int $bedroom,
        public ?string $email,
        public ?string $phoneNumber,
        public string $lang,
        public ?string $ip,
        public \DateTimeImmutable $createdAt,
    ) {}
}
