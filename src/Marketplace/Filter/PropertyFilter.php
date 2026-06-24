<?php

namespace App\Marketplace\Filter;

use App\Marketplace\Domain\Property;
use App\Marketplace\Repository\PropertyRepository;

/**
 * Stateless filtering of Property DTOs.
 * Filters are applied in PHP after fetching the full list (cached upstream).
 */
final class PropertyFilter
{
    public function __construct(
        private readonly PropertyRepository $propertyRepository,
    ) {
    }

    /**
     * @param array<int, Property> $properties
     * @param array<int, int>      $arrondissements
     *
     * @return array<int, Property>
     */
    public function apply(
        array $properties,
        array $arrondissements = [],
        ?string $propertyType = null,
        ?int $rentMin = null,
        ?int $rentMax = null,
    ): array {
        if ([] !== $arrondissements) {
            $codes = array_map(static fn (int $a) => sprintf('750%02d', $a), $arrondissements);
            $properties = array_values(array_filter(
                $properties,
                fn (Property $p) => in_array($p->address['postalCode'] ?? '', $codes, true)
            ));
        }

        if (null !== $propertyType && '' !== $propertyType) {
            $matchSlugs = $this->propertyRepository->findMatchingTypeSlugs($propertyType);
            $properties = array_values(array_filter(
                $properties,
                fn (Property $p) => in_array($p->propertyTypeSlug ?? '', $matchSlugs, true)
            ));
        }

        if (null !== $rentMin) {
            $properties = array_values(array_filter(
                $properties,
                fn (Property $p) => null !== $p->monthlyRent && $p->monthlyRent >= $rentMin
            ));
        }

        if (null !== $rentMax) {
            $properties = array_values(array_filter(
                $properties,
                fn (Property $p) => null !== $p->monthlyRent && $p->monthlyRent <= $rentMax
            ));
        }

        return $properties;
    }
}
