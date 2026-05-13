<?php

namespace App\Marketplace\Domain;

/**
 * Typed snapshot of a Sanity property document.
 *
 * Top-level fields are typed; sub-objects (address, mainPhoto, photos,
 * location, agent, tenant, faq, extraFees) stay as arrays for now —
 * promoting them to DTOs is a follow-up if their schema stabilizes.
 * Twig still accesses them with `property.address.city` etc.
 *
 * Field names mirror the GROQ projection in PropertyRepository::PROPERTY_PROJECTION
 * so templates can keep using the same dotted accessors.
 */
final readonly class Property
{
    public function __construct(
        public string $id,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $uniqueId = null,
        public ?string $title = null,
        public ?string $metaDescription = null,
        public ?string $bedrooms = null,
        public ?int $bathrooms = null,
        public ?int $monthlyRent = null,
        public ?bool $priceOnRequest = null,
        public ?bool $chargesIncludes = null,
        public ?int $chargesAmount = null,
        public ?string $listingUrl = null,
        public ?bool $showCategoryOnCard = null,
        public ?string $status = null,
        /** @var array<int, string>|null */
        public ?array $leaseType = null,
        public ?string $listingTypeName = null,
        public ?bool $longTerm = null,
        public ?bool $midTerm = null,
        public ?string $slug = null,
        /** @var array{city?: string, postalCode?: string, street?: string, number?: string}|null */
        public ?array $address = null,
        /** @var array{url?: string, alt?: string}|null */
        public ?array $mainPhoto = null,
        /** @var array<int, array{url?: string, alt?: string}>|null */
        public ?array $photos = null,
        public ?\DateTimeImmutable $availableDate = null,
        public ?string $agentPhoto = null,
        public ?string $agentName = null,
        /** @var array<string, mixed>|null */
        public ?array $tenant = null,
        public ?int $photoCount = null,
        public ?string $categoryName = null,
        /** @var array<int, array{name?: string, slug?: string}>|null */
        public ?array $categoryList = null,
        /** @var array{lat?: float, lng?: float}|null */
        public ?array $location = null,
        public ?bool $elevator = null,
        /** @var array<string, mixed>|null */
        public ?array $equipment = null,
        public ?int $floor = null,
        public ?int $totalFloors = null,
        /** @var array<int, string>|null */
        public ?array $exposure = null,
        /** @var array<int, string>|null */
        public ?array $view = null,
        public ?int $constructionYear = null,
        public ?int $renovationYear = null,
        /** @var array<int, array<string, mixed>>|null */
        public ?array $faq = null,
        /** @var array<int, array<string, mixed>>|null */
        public ?array $extraFees = null,
        public ?string $furnished = null,
        public ?string $bedroomsLabel = null,
        public ?int $squareMeters = null,
        public ?string $propertyTypeName = null,
        public ?string $propertyTypeSlug = null,
        public ?string $propertyTypeLang = null,
        /** @var array<int, string>|null */
        public ?array $metro = null,
        /** @var array<int, string>|null */
        public ?array $rer = null,
        /** @var array<int, string>|null */
        public ?array $tags = null,
        public ?string $internalNotes = null,
        public mixed $description = null,
        public ?self $alternateProperty = null,
    ) {
    }

    /**
     * Convenience for legacy templates / code paths that still address fields
     * using the original Sanity key (`_id` instead of `id`). Twig will resolve
     * `property._id` via this getter.
     */
    public function get_id(): string
    {
        return $this->id;
    }
}
