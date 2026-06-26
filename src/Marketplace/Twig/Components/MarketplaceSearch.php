<?php

namespace App\Marketplace\Twig\Components;

use App\Marketplace\Filter\PropertyFilter;
use App\Marketplace\Filter\PropertySearchCriteria;
use App\Marketplace\Map\MapBuilder;
use App\Marketplace\Reference\ParisArrondissements;
use App\Marketplace\Repository\PropertyRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\Live\ComponentWithMapTrait;
use Symfony\UX\Map\Map;

#[AsLiveComponent(name: 'Marketplace:Search', template: 'components/Marketplace/Search.html.twig')]
final class MarketplaceSearch
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    private const PER_PAGE = 24;
    private const ALLOWED_LOCALES = ['fr', 'en'];

    /** Bedroom counts offered in the "Layout" group (raw Sanity values). */
    private const ALLOWED_BEDROOMS = ['studio', '1', '2', '3', '4'];
    /** Equipment keys offered in the "Apartment features" section. */
    private const ALLOWED_FEATURES = ['balcony', 'elevator', 'parking', 'airConditioning'];
    private const ALLOWED_FURNISHED = ['yes', 'no'];
    private const ALLOWED_AVAILABILITY = ['now', '30days'];
    /** Budget slider bounds; the floor means "no minimum". */
    public const RENT_FLOOR = 800;
    public const RENT_CEILING = 10000;

    /**
     * Curated Paris spots shown at the top of the localisation panel. Clicking
     * one selects its arrondissement(s) and pins the area on the map.
     *
     * @var array<int, array{key: string, arrondissements: array<int, int>}>
     */
    private const POPULAR_AREAS = [
        ['key' => 'marais', 'arrondissements' => [3, 4]],
        ['key' => 'saintMichel', 'arrondissements' => [5, 6]],
        ['key' => 'champsElysees', 'arrondissements' => [8]],
        ['key' => 'montmartre', 'arrondissements' => [18]],
    ];

    /** @var array<int, array{key: string, arrondissements: array<int, int>}> */
    private const FAMILY_AREAS = [
        ['key' => 'beaugrenelle', 'arrondissements' => [15]],
        ['key' => 'auteuil', 'arrondissements' => [16]],
        ['key' => 'batignolles', 'arrondissements' => [17]],
        ['key' => 'bercy', 'arrondissements' => [12]],
    ];

    /* ----------------- Applied filters (URL-synced) ----------------- */

    #[LiveProp(writable: true, url: true)]
    public ?string $q = null;

    /** @var array<int, int> */
    #[LiveProp(writable: true, url: true)]
    public array $arrondissements = [];

    /** @var array<int, string> */
    #[LiveProp(writable: true, url: true)]
    public array $bedrooms = [];

    /** @var array<int, string> */
    #[LiveProp(writable: true, url: true)]
    public array $furnished = [];

    #[LiveProp(writable: true, url: true)]
    public bool $longTerm = false;

    #[LiveProp(writable: true, url: true)]
    public bool $midTerm = false;

    #[LiveProp(writable: true, url: true)]
    public ?int $rentMin = null;

    /** @var array<int, string> */
    #[LiveProp(writable: true, url: true)]
    public array $features = [];

    #[LiveProp(writable: true, url: true)]
    public ?string $availability = null;

    #[LiveProp(writable: true, url: true)]
    public bool $nearMetro = false;

    #[LiveProp(writable: true, url: true)]
    public bool $nearRer = false;

    /* ----------------- Drafts (mutated by inputs, applied on search) ----------------- */

    #[LiveProp(writable: true)]
    public ?string $draftQ = null;

    /** @var array<int, int> */
    #[LiveProp(writable: true)]
    public array $draftArrondissements = [];

    /** @var array<int, string> */
    #[LiveProp(writable: true)]
    public array $draftBedrooms = [];

    /** @var array<int, string> */
    #[LiveProp(writable: true)]
    public array $draftFurnished = [];

    #[LiveProp(writable: true)]
    public bool $draftLongTerm = false;

    #[LiveProp(writable: true)]
    public bool $draftMidTerm = false;

    #[LiveProp(writable: true)]
    public ?int $draftRentMin = null;

    /** @var array<int, string> */
    #[LiveProp(writable: true)]
    public array $draftFeatures = [];

    #[LiveProp(writable: true)]
    public ?string $draftAvailability = null;

    #[LiveProp(writable: true)]
    public bool $draftNearMetro = false;

    #[LiveProp(writable: true)]
    public bool $draftNearRer = false;

    /* ----------------- Map state ----------------- */

    #[LiveProp(writable: true)]
    public float $zoom = 12;

    #[LiveProp(writable: true)]
    public ?float $south = null;

    #[LiveProp(writable: true)]
    public ?float $north = null;

    #[LiveProp(writable: true)]
    public ?float $west = null;

    #[LiveProp(writable: true)]
    public ?float $east = null;

    /** Location pin (selected arrondissement / curated area center). */
    #[LiveProp(writable: true)]
    public ?float $pingLat = null;

    #[LiveProp(writable: true)]
    public ?float $pingLng = null;

    /** True when the pin sits on a precise address (Google Places) rather than an arrondissement centroid. */
    #[LiveProp(writable: true)]
    public bool $pingExplicit = false;

    /** @var array<int, string> */
    #[LiveProp(writable: true)]
    public array $spideredPropertyIds = [];

    #[LiveProp]
    public int $page = 1;

    #[LiveProp]
    public string $locale = 'fr';

    /* Snapshots used by PreReRender to detect filter changes. */

    /** @var array<int, int> */
    #[LiveProp(writable: false)]
    public array $prevArrondissements = [];

    #[LiveProp(writable: false)]
    public string $prevFilterKey = '';

    /* ----------------- In-memory caches (per render) ----------------- */

    /** @var array<int, \App\Marketplace\Domain\Property>|null */
    private ?array $filteredCache = null;
    private ?string $filteredCacheKey = null;

    public function __construct(
        private readonly PropertyRepository $propertyRepository,
        private readonly PropertyFilter $propertyFilter,
        private readonly MapBuilder $mapBuilder,
    ) {
    }

    /**
     * @param array<int, int|string> $arrondissements
     * @param array<int, string>     $bedrooms
     * @param array<int, string>     $features
     */
    public function mount(
        string $locale = 'fr',
        ?string $q = null,
        array $arrondissements = [],
        array $bedrooms = [],
        array $furnished = [],
        bool $longTerm = false,
        bool $midTerm = false,
        ?int $rentMin = null,
        array $features = [],
        ?string $availability = null,
        bool $nearMetro = false,
        bool $nearRer = false,
    ): void {
        $this->locale = in_array($locale, self::ALLOWED_LOCALES, true) ? $locale : 'fr';

        $this->q = $this->normalizeQ($q);
        $this->arrondissements = $this->normalizeArrondissements($arrondissements);
        $this->bedrooms = $this->normalizeWhitelist($bedrooms, self::ALLOWED_BEDROOMS);
        $this->furnished = $this->normalizeWhitelist($furnished, self::ALLOWED_FURNISHED);
        $this->longTerm = $longTerm;
        $this->midTerm = $midTerm;
        $this->rentMin = $this->normalizeRentMin($rentMin);
        $this->features = $this->normalizeWhitelist($features, self::ALLOWED_FEATURES);
        $this->availability = in_array($availability, self::ALLOWED_AVAILABILITY, true) ? $availability : null;
        $this->nearMetro = $nearMetro;
        $this->nearRer = $nearRer;

        $this->copyAppliedToDraft();
        $this->updatePing();

        $this->prevArrondissements = $this->arrondissements;
        $this->prevFilterKey = $this->filterKey();
    }

    /* ----------------- Normalisation ----------------- */

    private function normalizeQ(?string $q): ?string
    {
        $q = null !== $q ? trim($q) : '';

        return '' !== $q ? mb_substr($q, 0, 120) : null;
    }

    /** A min rent at (or below) the slider floor means "no minimum". */
    private function normalizeRentMin(?int $v): ?int
    {
        return null !== $v && $v > self::RENT_FLOOR ? min($v, self::RENT_CEILING) : null;
    }

    /**
     * @param array<int, int|string> $values
     *
     * @return array<int, int>
     */
    private function normalizeArrondissements(array $values): array
    {
        $valid = array_filter(
            array_map(static fn ($v) => (int) $v, $values),
            static fn (int $i) => $i >= 1 && $i <= 20,
        );
        $valid = array_values(array_unique($valid));
        sort($valid);

        return $valid;
    }

    /**
     * @param array<int, string> $values
     * @param array<int, string> $allowed
     *
     * @return array<int, string>
     */
    private function normalizeWhitelist(array $values, array $allowed): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => (string) $v, $values),
            static fn (string $v) => in_array($v, $allowed, true),
        )));
    }

    private function copyAppliedToDraft(): void
    {
        $this->draftQ = $this->q;
        $this->draftArrondissements = $this->arrondissements;
        $this->draftBedrooms = $this->bedrooms;
        $this->draftFurnished = $this->furnished;
        $this->draftLongTerm = $this->longTerm;
        $this->draftMidTerm = $this->midTerm;
        // The slider always sits on a concrete value; the floor means "no minimum".
        $this->draftRentMin = $this->rentMin ?? self::RENT_FLOOR;
        $this->draftFeatures = $this->features;
        $this->draftAvailability = $this->availability;
        $this->draftNearMetro = $this->nearMetro;
        $this->draftNearRer = $this->nearRer;
    }

    /** Recompute the map pin from the currently applied arrondissements (centroid). */
    private function updatePing(): void
    {
        // An arrondissement-driven ping is never explicit (that is reserved for picked addresses).
        $this->pingExplicit = false;

        if ([] === $this->arrondissements) {
            $this->pingLat = null;
            $this->pingLng = null;

            return;
        }

        $lats = [];
        $lngs = [];
        foreach ($this->arrondissements as $a) {
            [$lat, $lng] = ParisArrondissements::getCenter($a);
            $lats[] = $lat;
            $lngs[] = $lng;
        }

        $this->pingLat = array_sum($lats) / count($lats);
        $this->pingLng = array_sum($lngs) / count($lngs);
    }

    /** The single arrondissement to recenter the map on, or null when 0 or many are selected. */
    private function focusArrondissement(): ?int
    {
        return 1 === count($this->arrondissements) ? $this->arrondissements[0] : null;
    }

    /** Stable signature of every non-arrondissement filter (drives marker refresh). */
    private function filterKey(): string
    {
        return implode('|', [
            $this->q ?? '',
            implode(',', $this->bedrooms),
            implode(',', $this->furnished),
            $this->longTerm ? '1' : '0',
            $this->midTerm ? '1' : '0',
            $this->rentMin ?? '',
            implode(',', $this->features),
            $this->availability ?? '',
            $this->nearMetro ? '1' : '0',
            $this->nearRer ? '1' : '0',
        ]);
    }

    /* ----------------- Live actions ----------------- */

    #[LiveAction]
    public function more(): void
    {
        ++$this->page;
    }

    #[LiveAction]
    public function search(): void
    {
        $this->q = $this->normalizeQ($this->draftQ);
        $this->arrondissements = $this->normalizeArrondissements($this->draftArrondissements);
        $this->bedrooms = $this->normalizeWhitelist($this->draftBedrooms, self::ALLOWED_BEDROOMS);
        $this->furnished = $this->normalizeWhitelist($this->draftFurnished, self::ALLOWED_FURNISHED);
        $this->longTerm = $this->draftLongTerm;
        $this->midTerm = $this->draftMidTerm;
        $this->rentMin = $this->normalizeRentMin($this->draftRentMin);
        $this->features = $this->normalizeWhitelist($this->draftFeatures, self::ALLOWED_FEATURES);
        $this->availability = in_array($this->draftAvailability, self::ALLOWED_AVAILABILITY, true) ? $this->draftAvailability : null;
        $this->nearMetro = $this->draftNearMetro;
        $this->nearRer = $this->draftNearRer;

        $this->updatePing();
        $this->page = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->q = null;
        $this->arrondissements = [];
        $this->bedrooms = [];
        $this->furnished = [];
        $this->longTerm = false;
        $this->midTerm = false;
        $this->rentMin = null;
        $this->features = [];
        $this->availability = null;
        $this->nearMetro = false;
        $this->nearRer = false;

        $this->copyAppliedToDraft();
        $this->updatePing();
        $this->page = 1;
    }

    #[LiveAction]
    public function clearArrondissements(): void
    {
        $this->draftArrondissements = [];
    }

    /** Select a curated area: filters its arrondissement(s) and pins it on the map. */
    #[LiveAction]
    public function selectArea(
        #[LiveArg]
        string $arrondissements,
    ): void {
        $list = $this->normalizeArrondissements(explode(',', $arrondissements));
        $this->arrondissements = $list;
        $this->draftArrondissements = $list;
        $this->updatePing();
        $this->page = 1;
    }

    /**
     * Apply a precise address picked from Google Places: pin + recenter the map
     * on the exact coordinates and (when resolvable) filter by its arrondissement.
     */
    #[LiveAction]
    public function searchAddress(
        #[LiveArg]
        float $lat,
        #[LiveArg]
        float $lng,
        #[LiveArg]
        int $arrondissement = 0,
    ): void {
        if ($arrondissement >= 1 && $arrondissement <= 20) {
            $this->arrondissements = [$arrondissement];
            $this->draftArrondissements = [$arrondissement];
        }

        $this->pingLat = $lat;
        $this->pingLng = $lng;
        $this->pingExplicit = true;

        // Recenter the map on the exact address (full rebuild, closer zoom).
        $this->south = $this->north = $this->west = $this->east = null;
        $this->zoom = 16;
        $this->map = null;
        $this->spideredPropertyIds = [];
        $this->page = 1;

        // Snapshots updated so PreReRender does not override the explicit recenter.
        $this->prevArrondissements = $this->arrondissements;
        $this->prevFilterKey = $this->filterKey();
    }

    #[LiveAction]
    public function updateBounds(
        #[LiveArg]
        float $zoom,
        #[LiveArg]
        float $south,
        #[LiveArg]
        float $north,
        #[LiveArg]
        float $west,
        #[LiveArg]
        float $east,
    ): void {
        $this->zoom = $zoom;
        $this->south = $south;
        $this->north = $north;
        $this->west = $west;
        $this->east = $east;

        $map = $this->getMap();
        $map->removeAllMarkers();
        $this->refreshMarkers($map);
    }

    #[LiveAction]
    public function spiderCluster(
        #[LiveArg]
        string $propertyIds,
    ): void {
        $this->spideredPropertyIds = array_values(array_filter(
            array_map('trim', explode(',', $propertyIds)),
            static fn (string $id) => '' !== $id,
        ));

        $map = $this->getMap();
        $map->removeAllMarkers();
        $this->refreshMarkers($map);
    }

    #[PreReRender]
    public function refreshMapMarkers(): void
    {
        $arrondissementChanged = $this->arrondissements !== $this->prevArrondissements;
        $filterKey = $this->filterKey();
        $changed = $arrondissementChanged || $filterKey !== $this->prevFilterKey;

        if (!$changed) {
            return;
        }

        // Filter change invalidates any active spider-fy.
        $this->spideredPropertyIds = [];

        if ($arrondissementChanged) {
            // Full map reset (new center + zoom).
            $this->south = null;
            $this->north = null;
            $this->west = null;
            $this->east = null;
            $this->zoom = ParisArrondissements::defaultZoom($this->focusArrondissement());
            $this->map = null;
        } else {
            $map = $this->getMap();
            $map->removeAllMarkers();
            $this->refreshMarkers($map);
        }

        $this->prevArrondissements = $this->arrondissements;
        $this->prevFilterKey = $filterKey;
    }

    /* ----------------- Template accessors ----------------- */

    public function getItems(): array
    {
        return array_slice($this->getFilteredProperties(), 0, $this->page * self::PER_PAGE);
    }

    public function hasMore(): bool
    {
        return count($this->getFilteredProperties()) > $this->page * self::PER_PAGE;
    }

    public function getTotalCount(): int
    {
        return count($this->getFilteredProperties());
    }

    /** Active "More filters" count from applied state (pill badge). */
    public function getMoreFiltersCount(): int
    {
        return $this->criteriaFrom(false)->moreFiltersCount();
    }

    /** Active "More filters" count from draft state (modal footer). */
    public function getDraftMoreFiltersCount(): int
    {
        return $this->criteriaFrom(true)->moreFiltersCount();
    }

    /** Selected count for the Property type pill (draft state). */
    public function getPropertyTypeCount(): int
    {
        return count($this->draftBedrooms)
            + count($this->draftFurnished)
            + ($this->draftLongTerm ? 1 : 0)
            + ($this->draftMidTerm ? 1 : 0);
    }

    /**
     * Curated "popular areas" for the panel: each entry carries its i18n key and
     * a comma-joined list of arrondissements to select on click.
     *
     * @return array<int, array{key: string, csv: string}>
     */
    public function getPopularAreas(): array
    {
        return $this->mapAreas(self::POPULAR_AREAS);
    }

    /** @return array<int, array{key: string, csv: string}> */
    public function getFamilyAreas(): array
    {
        return $this->mapAreas(self::FAMILY_AREAS);
    }

    /**
     * @param array<int, array{key: string, arrondissements: array<int, int>}> $areas
     *
     * @return array<int, array{key: string, csv: string}>
     */
    private function mapAreas(array $areas): array
    {
        return array_map(
            static fn (array $a) => ['key' => $a['key'], 'csv' => implode(',', $a['arrondissements'])],
            $areas,
        );
    }

    /**
     * Neighbourhood label for each arrondissement (1–20) in the current locale.
     *
     * @return array<int, string>
     */
    public function getArrondissementNames(): array
    {
        $names = [];
        foreach (array_keys(ParisArrondissements::NAMES) as $i) {
            $names[$i] = ParisArrondissements::name($i, $this->locale);
        }

        return $names;
    }

    /** Bedroom values offered in the Layout group. */
    public function getBedroomOptions(): array
    {
        return self::ALLOWED_BEDROOMS;
    }

    /** Equipment keys offered in the Apartment features section. */
    public function getFeatureOptions(): array
    {
        return self::ALLOWED_FEATURES;
    }

    /* ----------------- Internals ----------------- */

    protected function instantiateMap(): Map
    {
        $centerLat = $this->pingExplicit ? $this->pingLat : null;
        $centerLng = $this->pingExplicit ? $this->pingLng : null;
        $map = $this->mapBuilder->buildMap($this->focusArrondissement(), $this->zoom, $centerLat, $centerLng);
        $this->refreshMarkers($map);

        return $map;
    }

    private function refreshMarkers(Map $map): void
    {
        $bounds = $this->mapBuilder->resolveBounds($this->south, $this->north, $this->west, $this->east);
        $this->mapBuilder->addMarkers(
            $map,
            $this->getFilteredProperties(),
            $bounds,
            $this->zoom,
            $this->locale,
            $this->spideredPropertyIds,
        );
        $this->mapBuilder->addPing($map, $this->pingLat, $this->pingLng);
    }

    private function criteriaFrom(bool $draft): PropertySearchCriteria
    {
        return $draft
            ? new PropertySearchCriteria(
                q: $this->draftQ,
                arrondissements: $this->draftArrondissements,
                bedrooms: $this->draftBedrooms,
                furnished: $this->draftFurnished,
                longTerm: $this->draftLongTerm,
                midTerm: $this->draftMidTerm,
                rentMin: $this->normalizeRentMin($this->draftRentMin),
                features: $this->draftFeatures,
                availability: $this->draftAvailability,
                nearMetro: $this->draftNearMetro,
                nearRer: $this->draftNearRer,
            )
            : new PropertySearchCriteria(
                q: $this->q,
                arrondissements: $this->arrondissements,
                bedrooms: $this->bedrooms,
                furnished: $this->furnished,
                longTerm: $this->longTerm,
                midTerm: $this->midTerm,
                rentMin: $this->rentMin,
                features: $this->features,
                availability: $this->availability,
                nearMetro: $this->nearMetro,
                nearRer: $this->nearRer,
            );
    }

    private function getFilteredProperties(): array
    {
        $key = implode('||', [implode(',', $this->arrondissements), $this->filterKey()]);
        if (null !== $this->filteredCache && $this->filteredCacheKey === $key) {
            return $this->filteredCache;
        }

        $filtered = $this->propertyFilter->apply(
            $this->propertyRepository->findAll($this->locale),
            $this->criteriaFrom(false),
        );

        $this->filteredCacheKey = $key;
        $this->filteredCache = $filtered;

        return $filtered;
    }
}
