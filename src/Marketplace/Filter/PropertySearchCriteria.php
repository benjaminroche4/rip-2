<?php

namespace App\Marketplace\Filter;

/**
 * Immutable set of marketplace search filters. Every field maps to a real
 * Sanity property field (see PropertyFilter) so the UI never offers a filter
 * without data behind it.
 */
final readonly class PropertySearchCriteria
{
    /**
     * @param array<int, int>    $arrondissements Paris arrondissements 1–20
     * @param array<int, string> $bedrooms        raw bedroom values ("studio", "1"…"4")
     * @param array<int, string> $furnished       "yes" and/or "no" (logical OR)
     * @param array<int, string> $features        equipment keys, all required (logical AND)
     */
    public function __construct(
        public ?string $q = null,
        public array $arrondissements = [],
        public array $bedrooms = [],
        public array $furnished = [],
        public bool $longTerm = false,
        public bool $midTerm = false,
        public ?int $rentMin = null,
        public array $features = [],
        public ?string $availability = null, // 'now' | '30days'
        public bool $nearMetro = false,
        public bool $nearRer = false,
    ) {
    }

    /** Number of active "More filters" selections, for the pill badge. */
    public function moreFiltersCount(): int
    {
        return (null !== $this->rentMin ? 1 : 0)
            + count($this->features)
            + (null !== $this->availability ? 1 : 0)
            + ($this->nearMetro ? 1 : 0)
            + ($this->nearRer ? 1 : 0);
    }
}
