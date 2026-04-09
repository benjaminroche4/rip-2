<?php

namespace App\Marketplace\Filter;

use App\Marketplace\Repository\PropertyRepository;

/**
 * Stateless filtering of property arrays.
 * Filters are applied in PHP after fetching the full list (cached upstream).
 */
final class PropertyFilter
{
    public function __construct(
        private readonly PropertyRepository $propertyRepository,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $properties
     * @return array<int, array<string, mixed>>
     */
    public function apply(
        array $properties,
        ?int $arrondissement = null,
        string $propertyType = '',
        ?int $rentMin = null,
        ?int $rentMax = null,
    ): array {
        if ($arrondissement !== null) {
            $code = sprintf('750%02d', $arrondissement);
            $properties = array_values(array_filter(
                $properties,
                fn (array $p) => ($p['address']['postalCode'] ?? '') === $code
            ));
        }

        if ($propertyType !== '') {
            $matchSlugs = $this->propertyRepository->findMatchingTypeSlugs($propertyType);
            $properties = array_values(array_filter(
                $properties,
                fn (array $p) => in_array($p['propertyTypeSlug'] ?? '', $matchSlugs, true)
            ));
        }

        if ($rentMin !== null) {
            $properties = array_values(array_filter(
                $properties,
                fn (array $p) => !empty($p['monthlyRent']) && $p['monthlyRent'] >= $rentMin
            ));
        }

        if ($rentMax !== null) {
            $properties = array_values(array_filter(
                $properties,
                fn (array $p) => !empty($p['monthlyRent']) && $p['monthlyRent'] <= $rentMax
            ));
        }

        return $properties;
    }
}
