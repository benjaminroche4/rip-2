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

#[AsLiveComponent]
final class MarketplaceSearch
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    private const PER_PAGE = 24;
    private const ALLOWED_LOCALES = ['fr', 'en'];

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

    /** @var array<int, \App\Marketplace\Domain\Property>|null */
    private ?array $filteredCache = null;
    private ?string $filteredCacheKey = null;

    public function __construct(
        private readonly PropertyRepository $propertyRepository,
        private readonly PropertyFilter $propertyFilter,
        private readonly MapBuilder $mapBuilder,
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
        $this->normalizeRentBounds();

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
        $this->normalizeRentBounds();
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
        $this->refreshMarkers($map);
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
            // Reset complet de la carte (nouveau centre + zoom).
            $this->south = null;
            $this->north = null;
            $this->west = null;
            $this->east = null;
            $this->zoom = ParisArrondissements::defaultZoom($this->arrondissement);
            $this->map = null;
        } else {
            $map = $this->getMap();
            $map->removeAllMarkers();
            $this->refreshMarkers($map);
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

    protected function instantiateMap(): Map
    {
        $map = $this->mapBuilder->buildMap($this->arrondissement, $this->zoom);
        $this->refreshMarkers($map);

        return $map;
    }

    private function refreshMarkers(Map $map): void
    {
        $bounds = $this->mapBuilder->resolveBounds($this->south, $this->north, $this->west, $this->east);
        $this->mapBuilder->addMarkers($map, $this->getFilteredProperties(), $bounds, $this->zoom, $this->locale);
    }

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

    private function normalizeRentBounds(): void
    {
        if ($this->draftRentMin !== null && $this->draftRentMax !== null && $this->draftRentMax < $this->draftRentMin) {
            [$this->draftRentMin, $this->draftRentMax] = [$this->draftRentMax, $this->draftRentMin];
        }
    }
}
