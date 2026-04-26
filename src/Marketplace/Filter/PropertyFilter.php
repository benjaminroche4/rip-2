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
     *
     * @return array<int, Property>
     */
    public function apply(
        array $properties,
        ?int $arrondissement = null,
        string $propertyType = '',
        ?int $rentMin = null,
        ?int $rentMax = null,
    ): array {
        if (null !== $arrondissement) {
            $code = sprintf('750%02d', $arrondissement);
            $properties = array_values(array_filter(
                $properties,
                fn (Property $p) => ($p->address['postalCode'] ?? '') === $code
            ));
        }

        if ('' !== $propertyType) {
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
