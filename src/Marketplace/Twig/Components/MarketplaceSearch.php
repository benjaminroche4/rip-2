<?php

namespace App\Marketplace\Twig\Components;

use App\Marketplace\Filter\PropertyFilter;
use App\Marketplace\Map\MarkerBuilder;
use App\Marketplace\Repository\PropertyRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Cluster\GridClusteringAlgorithm;
use Symfony\UX\Map\Live\ComponentWithMapTrait;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Point;

#[AsLiveComponent]
final class MarketplaceSearch
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    private const PER_PAGE = 24;
    private const ALLOWED_LOCALES = ['fr', 'en'];

    /** Centre approximatif de chaque arrondissement de Paris */
    private const ARRONDISSEMENT_CENTERS = [
        1  => [48.8606, 2.3376],
        2  => [48.8682, 2.3417],
        3  => [48.8630, 2.3601],
        4  => [48.8550, 2.3578],
        5  => [48.8443, 2.3500],
        6  => [48.8488, 2.3325],
        7  => [48.8567, 2.3127],
        8  => [48.8718, 2.3119],
        9  => [48.8769, 2.3372],
        10 => [48.8762, 2.3601],
        11 => [48.8594, 2.3782],
        12 => [48.8400, 2.3877],
        13 => [48.8322, 2.3561],
        14 => [48.8331, 2.3264],
        15 => [48.8417, 2.2986],
        16 => [48.8603, 2.2620],
        17 => [48.8848, 2.3076],
        18 => [48.8925, 2.3444],
        19 => [48.8847, 2.3845],
        20 => [48.8631, 2.4007],
    ];

    /* ----------------- Live state ----------------- */

    #[LiveProp(writable: true, url: true)]
    public ?int $arrondissement = null;

    #[LiveProp(writable: true, url: true)]
    public string $propertyType = '';

    #[LiveProp(writable: true, url: true)]
    public ?int $rentMin = null;

    #[LiveProp(writable: true, url: true)]
    public ?int $rentMax = null;

    /* Drafts modifiés par les inputs sans déclencher de re-render. Appliqués au clic "Rechercher". */

    #[LiveProp(writable: true)]
    public ?int $draftArrondissement = null;

    #[LiveProp(writable: true)]
    public string $draftPropertyType = '';

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

    #[LiveProp]
    public int $page = 1;

    #[LiveProp]
    public string $locale = 'fr';

    /* Snapshots used by PreReRender to detect filter changes. */

    #[LiveProp(writable: false)]
    public ?int $prevArrondissement = null;

    #[LiveProp(writable: false)]
    public string $prevPropertyType = '';

    #[LiveProp(writable: false)]
    public ?int $prevRentMin = null;

    #[LiveProp(writable: false)]
    public ?int $prevRentMax = null;

    /* ----------------- In-memory caches (per render) ----------------- */

    /** @var array<int, array<string, mixed>>|null */
    private ?array $filteredCache = null;
    private ?string $filteredCacheKey = null;

    public function __construct(
        private readonly PropertyRepository $propertyRepository,
        private readonly PropertyFilter $propertyFilter,
        private readonly MarkerBuilder $markerBuilder,
    ) {}

    public function mount(
        string $locale = 'fr',
        ?int $arrondissement = null,
        string $propertyType = '',
        ?int $rentMin = null,
        ?int $rentMax = null,
    ): void {
        $this->locale = in_array($locale, self::ALLOWED_LOCALES, true) ? $locale : 'fr';
        $this->arrondissement = $arrondissement;
        $this->propertyType = $propertyType;
        $this->rentMin = $rentMin;
        $this->rentMax = $rentMax;

        $this->draftArrondissement = $this->arrondissement;
        $this->draftPropertyType = $this->propertyType;
        $this->draftRentMin = $this->rentMin;
        $this->draftRentMax = $this->rentMax;

        $this->prevArrondissement = $this->arrondissement;
        $this->prevPropertyType = $this->propertyType;
        $this->prevRentMin = $this->rentMin;
        $this->prevRentMax = $this->rentMax;
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
        if ($this->draftRentMin !== null && $this->draftRentMax !== null && $this->draftRentMax < $this->draftRentMin) {
            [$this->draftRentMin, $this->draftRentMax] = [$this->draftRentMax, $this->draftRentMin];
        }

        $this->arrondissement = $this->draftArrondissement;
        $this->propertyType = $this->draftPropertyType;
        $this->rentMin = $this->draftRentMin;
        $this->rentMax = $this->draftRentMax;

        $this->page = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->arrondissement = null;
        $this->propertyType = '';
        $this->rentMin = null;
        $this->rentMax = null;

        $this->draftArrondissement = null;
        $this->draftPropertyType = '';
        $this->draftRentMin = null;
        $this->draftRentMax = null;

        $this->page = 1;
    }

    #[LiveAction]
    public function normalizeRents(): void
    {
        if ($this->draftRentMin !== null && $this->draftRentMax !== null && $this->draftRentMax < $this->draftRentMin) {
            [$this->draftRentMin, $this->draftRentMax] = [$this->draftRentMax, $this->draftRentMin];
        }
    }

    #[LiveAction]
    public function updateBounds(
        #[LiveArg] float $zoom,
        #[LiveArg] float $south,
        #[LiveArg] float $north,
        #[LiveArg] float $west,
        #[LiveArg] float $east,
    ): void {
        $this->zoom = $zoom;
        $this->south = $south;
        $this->north = $north;
        $this->west = $west;
        $this->east = $east;

        $map = $this->getMap();
        $map->removeAllMarkers();
        $this->addMarkersToMap($map);
    }

    #[PreReRender]
    public function refreshMapMarkers(): void
    {
        $arrondissementChanged = $this->arrondissement !== $this->prevArrondissement;
        $changed = $arrondissementChanged
            || $this->propertyType !== $this->prevPropertyType
            || $this->rentMin !== $this->prevRentMin
            || $this->rentMax !== $this->prevRentMax;

        if (!$changed) {
            return;
        }

        if ($arrondissementChanged) {
            // Reset complet de la carte (nouveau centre + zoom)
            $this->south = null;
            $this->north = null;
            $this->west = null;
            $this->east = null;
            $this->zoom = ($this->arrondissement !== null && isset(self::ARRONDISSEMENT_CENTERS[$this->arrondissement])) ? 14 : 12;
            $this->map = null;
        } else {
            $map = $this->getMap();
            $map->removeAllMarkers();
            $this->addMarkersToMap($map);
        }

        $this->prevArrondissement = $this->arrondissement;
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

    /* ----------------- Internals ----------------- */

    private function getFilteredProperties(): array
    {
        $key = sprintf('%s|%s|%s|%s', $this->arrondissement ?? '', $this->propertyType, $this->rentMin ?? '', $this->rentMax ?? '');
        if ($this->filteredCache !== null && $this->filteredCacheKey === $key) {
            return $this->filteredCache;
        }

        $filtered = $this->propertyFilter->apply(
            $this->propertyRepository->findAll($this->locale),
            $this->arrondissement,
            $this->propertyType,
            $this->rentMin,
            $this->rentMax,
        );

        $this->filteredCacheKey = $key;
        $this->filteredCache = $filtered;

        return $filtered;
    }

    protected function instantiateMap(): Map
    {
        $center = ($this->arrondissement !== null && isset(self::ARRONDISSEMENT_CENTERS[$this->arrondissement]))
            ? new Point(self::ARRONDISSEMENT_CENTERS[$this->arrondissement][0], self::ARRONDISSEMENT_CENTERS[$this->arrondissement][1])
            : new Point(48.8566, 2.3522);

        $map = (new Map('default'))
            ->center($center)
            ->zoom($this->zoom)
            ->minZoom(9)
            ->maxZoom(17)
            ->options(new GoogleOptions(
                gestureHandling: GestureHandling::GREEDY,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ));

        $this->addMarkersToMap($map);

        return $map;
    }

    private function addMarkersToMap(Map $map): void
    {
        $properties = $this->getFilteredProperties();
        $bounds = $this->getActiveBounds();

        $validProperties = [];
        $points = [];

        foreach ($properties as $property) {
            if (empty($property['location']['lat']) || empty($property['location']['lng'])) {
                continue;
            }

            $lat = $property['location']['lat'];
            $lng = $property['location']['lng'];

            if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
                continue;
            }

            $points[] = new Point($lat, $lng);
            $validProperties[] = $property;
        }

        if (empty($points)) {
            return;
        }

        // Lookup "lat,lng" => list of properties at that point
        $pointToProperties = [];
        foreach ($validProperties as $i => $property) {
            $key = $points[$i]->getLatitude() . ',' . $points[$i]->getLongitude();
            $pointToProperties[$key][] = $property;
        }

        $clusters = (new GridClusteringAlgorithm())->cluster($points, $this->zoom);

        foreach ($clusters as $cluster) {
            if ($cluster->count() === 1) {
                $singlePoint = $cluster->getPoints()[0];
                $key = $singlePoint->getLatitude() . ',' . $singlePoint->getLongitude();
                $property = $pointToProperties[$key][0] ?? null;

                if ($property) {
                    $map->addMarker($this->markerBuilder->buildPropertyMarker($property, $this->locale));
                }
            } else {
                $clusterPropertyIds = [];
                foreach ($cluster->getPoints() as $point) {
                    $key = $point->getLatitude() . ',' . $point->getLongitude();
                    foreach ($pointToProperties[$key] ?? [] as $p) {
                        $clusterPropertyIds[] = $p['_id'];
                    }
                }
                $map->addMarker($this->markerBuilder->buildClusterMarker($cluster->getCenter(), $cluster->count(), $clusterPropertyIds, $this->locale));
            }
        }
    }

    /**
     * @return array{south: float, north: float, west: float, east: float}
     */
    private function getActiveBounds(): array
    {
        if ($this->south !== null && $this->north !== null && $this->west !== null && $this->east !== null) {
            return [
                'south' => $this->south,
                'north' => $this->north,
                'west' => $this->west,
                'east' => $this->east,
            ];
        }

        // Default: Paris + petite couronne
        return ['south' => 48.69, 'north' => 49.01, 'west' => 2.09, 'east' => 2.67];
    }
}
