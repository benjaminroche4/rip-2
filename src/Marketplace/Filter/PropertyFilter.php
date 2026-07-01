<?php

namespace App\Marketplace\Filter;

use App\Marketplace\Domain\Property;

/**
 * Stateless filtering of Property DTOs.
 * Filters are applied in PHP after fetching the full list (cached upstream).
 * Every criterion maps to a concrete Sanity field.
 */
final class PropertyFilter
{
    /**
     * @param array<int, Property> $properties
     *
     * @return array<int, Property>
     */
    public function apply(array $properties, PropertySearchCriteria $criteria, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('today');

        if (null !== $criteria->q && '' !== trim($criteria->q)) {
            $needle = mb_strtolower(trim($criteria->q));
            $properties = $this->keep($properties, fn (Property $p) => str_contains($this->haystack($p), $needle));
        }

        if ([] !== $criteria->arrondissements) {
            $codes = array_map(static fn (int $a) => sprintf('750%02d', $a), $criteria->arrondissements);
            $properties = $this->keep($properties, fn (Property $p) => in_array($p->address['postalCode'] ?? '', $codes, true));
        }

        if ([] !== $criteria->bedrooms) {
            $wanted = array_map('strval', $criteria->bedrooms);
            $properties = $this->keep($properties, fn (Property $p) => null !== $p->bedrooms && in_array($p->bedrooms, $wanted, true));
        }

        if ([] !== $criteria->furnished) {
            $properties = $this->keep($properties, fn (Property $p) => null !== $p->furnished && in_array($p->furnished, $criteria->furnished, true));
        }

        if ($criteria->longTerm) {
            $properties = $this->keep($properties, fn (Property $p) => true === $p->longTerm);
        }

        if ($criteria->midTerm) {
            $properties = $this->keep($properties, fn (Property $p) => true === $p->midTerm);
        }

        if (null !== $criteria->rentMax) {
            $properties = $this->keep($properties, fn (Property $p) => null !== $p->monthlyRent && $p->monthlyRent <= $criteria->rentMax);
        }

        if ([] !== $criteria->features) {
            // All requested equipment must be present (logical AND).
            $properties = $this->keep($properties, function (Property $p) use ($criteria) {
                foreach ($criteria->features as $feature) {
                    if (true !== ($p->equipment[$feature] ?? null)) {
                        return false;
                    }
                }

                return true;
            });
        }

        if (null !== $criteria->availability) {
            $limit = '30days' === $criteria->availability ? $now->modify('+30 days') : $now;
            // No date means available immediately; otherwise it must be reachable by the limit.
            $properties = $this->keep($properties, fn (Property $p) => null === $p->availableDate || $p->availableDate <= $limit);
        }

        if ($criteria->nearMetro) {
            $properties = $this->keep($properties, fn (Property $p) => [] !== ($p->metro ?? []));
        }

        if ($criteria->nearRer) {
            $properties = $this->keep($properties, fn (Property $p) => [] !== ($p->rer ?? []));
        }

        return $properties;
    }

    /**
     * @param array<int, Property> $properties
     * @param callable(Property): bool $predicate
     *
     * @return array<int, Property>
     */
    private function keep(array $properties, callable $predicate): array
    {
        return array_values(array_filter($properties, $predicate));
    }

    /** Lower-cased searchable text: title + address + tags. */
    private function haystack(Property $p): string
    {
        $parts = [
            $p->title ?? '',
            $p->address['street'] ?? '',
            $p->address['city'] ?? '',
            $p->address['postalCode'] ?? '',
        ];
        foreach ($p->tags ?? [] as $tag) {
            $parts[] = (string) $tag;
        }

        return mb_strtolower(implode(' ', $parts));
    }
}
