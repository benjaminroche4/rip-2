<?php

namespace App\PropertyListing\Message;

/**
 * Enum fields travel as translation keys: the admin email renders them in
 * French, the owner confirmation in the visitor's locale.
 */
final readonly class SendListingEmailMessage
{
    /**
     * @param list<string> $orientations
     * @param list<string> $leaseTypes
     * @param list<string> $amenities
     */
    public function __construct(
        public string $fullName,
        public string $email,
        public string $phoneNumber,
        public string $address,
        public string $propertyType,
        public string $propertyStatus,
        public int $bedrooms,
        public int $bathrooms,
        public int $surface,
        public ?int $floor,
        public ?int $buildingFloors,
        public string $furnishing,
        public array $orientations,
        public array $leaseTypes,
        public ?int $rent,
        public ?int $charges,
        public ?int $deposit,
        public array $amenities,
        public ?string $note,
        public int $photosCount,
        public ?string $photosFolder,
        public string $lang,
        public ?string $ip,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
