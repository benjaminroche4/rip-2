<?php

namespace App\Marketplace\Domain;

/**
 * Builds {@see Property} instances from raw GROQ result arrays.
 *
 * Centralizes every mapping decision (date parsing, key fallbacks) so that
 * a Sanity schema change shows up here once instead of being scattered
 * across templates and controllers.
 */
final class PropertyMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function fromGroqArray(array $row): Property
    {
        return new Property(
            id: (string) ($row['_id'] ?? ''),
            createdAt: $this->toDateTime($row['createdAt'] ?? null),
            updatedAt: $this->toDateTime($row['updatedAt'] ?? null),
            uniqueId: $this->toNullableString($row['uniqueId'] ?? null),
            title: $this->toNullableString($row['title'] ?? null),
            metaDescription: $this->toNullableString($row['metaDescription'] ?? null),
            bedrooms: $this->toNullableString($row['bedrooms'] ?? null),
            bathrooms: $this->toNullableInt($row['bathrooms'] ?? null),
            monthlyRent: $this->toNullableInt($row['monthlyRent'] ?? null),
            priceOnRequest: $this->toNullableBool($row['priceOnRequest'] ?? null),
            chargesIncludes: $this->toNullableBool($row['chargesIncludes'] ?? null),
            showCategoryOnCard: $this->toNullableBool($row['showCategoryOnCard'] ?? null),
            status: $this->toNullableString($row['status'] ?? null),
            leaseType: $this->toNullableString($row['leaseType'] ?? null),
            listingTypeName: $this->toNullableString($row['listingTypeName'] ?? null),
            longTerm: $this->toNullableBool($row['longTerm'] ?? null),
            midTerm: $this->toNullableBool($row['midTerm'] ?? null),
            slug: $this->toNullableString($row['slug'] ?? null),
            address: $this->toNullableArray($row['address'] ?? null),
            mainPhoto: $this->toNullableArray($row['mainPhoto'] ?? null),
            photos: $this->toNullableArray($row['photos'] ?? null),
            availableDate: $this->toDateTime($row['availableDate'] ?? null),
            agentPhoto: $this->toNullableString($row['agentPhoto'] ?? null),
            agentName: $this->toNullableString($row['agentName'] ?? null),
            tenant: $this->toNullableArray($row['tenant'] ?? null),
            photoCount: $this->toNullableInt($row['photoCount'] ?? null),
            categoryName: $this->toNullableString($row['categoryName'] ?? null),
            categoryList: $this->toNullableArray($row['categoryList'] ?? null),
            location: $this->toNullableArray($row['location'] ?? null),
            elevator: $this->toNullableBool($row['elevator'] ?? null),
            equipment: $this->toNullableArray($row['equipment'] ?? null),
            floor: $this->toNullableInt($row['floor'] ?? null),
            totalFloors: $this->toNullableInt($row['totalFloors'] ?? null),
            exposure: $this->toNullableString($row['exposure'] ?? null),
            view: $this->toNullableString($row['view'] ?? null),
            constructionYear: $this->toNullableInt($row['constructionYear'] ?? null),
            renovationYear: $this->toNullableInt($row['renovationYear'] ?? null),
            faq: $this->toNullableArray($row['faq'] ?? null),
            extraFees: $this->toNullableArray($row['extraFees'] ?? null),
            furnished: $this->toNullableString($row['furnished'] ?? null),
            bedroomsLabel: $this->toNullableString($row['bedroomsLabel'] ?? null),
            squareMeters: $this->toNullableInt($row['squareMeters'] ?? null),
            propertyTypeName: $this->toNullableString($row['propertyTypeName'] ?? null),
            propertyTypeSlug: $this->toNullableString($row['propertyTypeSlug'] ?? null),
            propertyTypeLang: $this->toNullableString($row['propertyTypeLang'] ?? null),
            metro: $this->toNullableArray($row['metro'] ?? null),
            rer: $this->toNullableArray($row['rer'] ?? null),
            tags: $this->toNullableArray($row['tags'] ?? null),
            internalNotes: $this->toNullableString($row['internalNotes'] ?? null),
            description: $row['description'] ?? null,
            alternateProperty: $this->toNullableArray($row['alternateProperty'] ?? null),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, Property>
     */
    public function fromGroqArrayList(array $rows): array
    {
        return array_values(array_map($this->fromGroqArray(...), $rows));
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function toNullableBool(mixed $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        return (bool) $value;
    }

    /**
     * @return array<mixed>|null
     */
    private function toNullableArray(mixed $value): ?array
    {
        return is_array($value) && [] !== $value ? $value : null;
    }
}
