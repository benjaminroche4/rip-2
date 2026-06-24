<?php

namespace App\Marketplace\Twig\Components;

use App\Marketplace\Filter\PropertyFilter;
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

    /* ----------------- Live state ----------------- */

    /** @var array<int, int> */
    #[LiveProp(writable: true, url: true)]
    public array $arrondissements = [];

    #[LiveProp(writable: true, url: true)]
    public ?string $propertyType = null;

    #[LiveProp(writable: true, url: true)]
    public ?int $rentMin = null;

    #[LiveProp(writable: true, url: true)]
    public ?int $rentMax = null;

    /* Drafts modifiés par les inputs sans déclencher de re-render. Appliqués au clic "Rechercher". */

    /** @var array<int, int> */
    #[LiveProp(writable: true)]
    public array $draftArrondissements = [];

    #[LiveProp(writable: true)]
    public ?string $draftPropertyType = null;

    #[LiveProp(writable: true)]
    public ?int $draftRentMin = null;

    #[LiveProp(writable: true)]
    public ?int $draftRentMax = null;

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
    public ?string $prevPropertyType = null;

    #[LiveProp(writable: false)]
    public ?int $prevRentMin = null;

    #[LiveProp(writable: false)]
    public ?int $prevRentMax = null;

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
     */
    public function mount(
        string $locale = 'fr',
        array $arrondissements = [],
        ?string $propertyType = null,
        ?int $rentMin = null,
        ?int $rentMax = null,
    ): void {
        $this->locale = in_array($locale, self::ALLOWED_LOCALES, true) ? $locale : 'fr';
        $this->arrondissements = $this->normalizeArrondissements($arrondissements);
        $this->propertyType = $propertyType ?: null;
        $this->rentMin = $rentMin;
        $this->rentMax = $rentMax;

        $this->draftArrondissements = $this->arrondissements;
        $this->draftPropertyType = $this->propertyType;
        $this->draftRentMin = $this->rentMin;
        $this->draftRentMax = $this->rentMax;

        $this->prevArrondissements = $this->arrondissements;
        $this->prevPropertyType = $this->propertyType;
        $this->prevRentMin = $this->rentMin;
        $this->prevRentMax = $this->rentMax;
    }

    /**
     * Keep only valid Paris arrondissements (1–20), unique and sorted.
     *
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

    /** The single arrondissement to recenter the map on, or null when 0 or many are selected. */
    private function focusArrondissement(): ?int
    {
        return 1 === count($this->arrondissements) ? $this->arrondissements[0] : null;
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
        $this->normalizeRentBounds();

        $this->arrondissements = $this->normalizeArrondissements($this->draftArrondissements);
        // Empty select ("Tous les biens") maps to null so it stays out of the URL.
        $this->propertyType = $this->draftPropertyType ?: null;
        $this->rentMin = $this->draftRentMin;
        $this->rentMax = $this->draftRentMax;

        $this->page = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->arrondissements = [];
        $this->propertyType = null;
        $this->rentMin = null;
        $this->rentMax = null;

        $this->draftArrondissements = [];
        $this->draftPropertyType = null;
        $this->draftRentMin = null;
        $this->draftRentMax = null;

        $this->page = 1;
    }

    #[LiveAction]
    public function clearArrondissements(): void
    {
        $this->arrondissements = [];
        $this->draftArrondissements = [];
        $this->page = 1;
    }

    #[LiveAction]
    public function normalizeRents(): void
    {
        $this->normalizeRentBounds();
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
        $changed = $arrondissementChanged
            || $this->propertyType !== $this->prevPropertyType
            || $this->rentMin !== $this->prevRentMin
            || $this->rentMax !== $this->prevRentMax;

        if (!$changed) {
            return;
        }

        // Filter change invalidates any active spider-fy.
        $this->spideredPropertyIds = [];

        if ($arrondissementChanged) {
            // Reset complet de la carte (nouveau centre + zoom).
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
        $this->prevPropertyType = $this->propertyType;
        $this->prevRentMin = $this->rentMin;
        $this->prevRentMax = $this->rentMax;
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

    public function getPropertyTypes(): array
    {
        return $this->propertyRepository->findPropertyTypes($this->locale);
    }

    /**
     * Neighbourhood label for each arrondissement (1–20) in the current locale,
     * used by the arrondissement filter panel.
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

    /* ----------------- Internals ----------------- */

    protected function instantiateMap(): Map
    {
        $map = $this->mapBuilder->buildMap($this->focusArrondissement(), $this->zoom);
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
    }

    private function getFilteredProperties(): array
    {
        $key = sprintf('%s|%s|%s|%s', implode(',', $this->arrondissements), $this->propertyType ?? '', $this->rentMin ?? '', $this->rentMax ?? '');
        if (null !== $this->filteredCache && $this->filteredCacheKey === $key) {
            return $this->filteredCache;
        }

        $filtered = $this->propertyFilter->apply(
            $this->propertyRepository->findAll($this->locale),
            $this->arrondissements,
            $this->propertyType,
            $this->rentMin,
            $this->rentMax,
        );

        $this->filteredCacheKey = $key;
        $this->filteredCache = $filtered;

        return $filtered;
    }

    private function normalizeRentBounds(): void
    {
        if (null !== $this->draftRentMin && null !== $this->draftRentMax && $this->draftRentMax < $this->draftRentMin) {
            [$this->draftRentMin, $this->draftRentMax] = [$this->draftRentMax, $this->draftRentMin];
        }
    }
}
