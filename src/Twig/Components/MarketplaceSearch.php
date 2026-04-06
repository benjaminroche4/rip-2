<?php

namespace App\Twig\Components;

use App\Service\SanityService;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Cluster\GridClusteringAlgorithm;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Live\ComponentWithMapTrait;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsLiveComponent]
final class MarketplaceSearch
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    #[LiveProp(writable: true, url: true)]
    public string $location = '';

    #[LiveProp(writable: true, url: true)]
    public string $propertyType = '';

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

    private const PER_PAGE = 2;

    /** @var array|null In-memory cache to avoid multiple fetchProperties() calls per render */
    private ?array $propertiesCache = null;

    public function __construct(
        private readonly SanityService $sanityService,
        private readonly CacheInterface $cache,
    ) {}

    #[LiveAction]
    public function more(): void
    {
        ++$this->page;
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

    #[LiveProp(writable: false)]
    public string $prevLocation = '';

    #[LiveProp(writable: false)]
    public string $prevPropertyType = '';

    #[PreReRender]
    public function refreshMapMarkers(): void
    {
        if ($this->location !== $this->prevLocation || $this->propertyType !== $this->prevPropertyType) {
            $map = $this->getMap();
            $map->removeAllMarkers();
            $this->addMarkersToMap($map);

            $this->prevLocation = $this->location;
            $this->prevPropertyType = $this->propertyType;
        }
    }

    public function getItems(): array
    {
        return array_slice($this->getFilteredProperties(), 0, $this->page * self::PER_PAGE);
    }

    public function hasMore(): bool
    {
        return count($this->getFilteredProperties()) > $this->page * self::PER_PAGE;
    }

    public function getPropertyTypes(): array
    {
        return $this->cache->get(
            'property_types_' . $this->locale,
            function (ItemInterface $item): array {
                $item->expiresAfter(300);

                $results = $this->sanityService->query(
                    '*[_type == "propertyType" && language == $lang] | order(name asc) { "slug": slug.current, name }',
                    ['lang' => $this->locale]
                );

                if (!is_array($results)) {
                    return [];
                }

                $types = [];
                foreach ($results as $type) {
                    if (!empty($type['slug']) && !empty($type['name'])) {
                        $types[$type['slug']] = $type['name'];
                    }
                }
                return $types;
            }
        );
    }

    public function getTotalCount(): int
    {
        return count($this->getFilteredProperties());
    }

    private function getFilteredProperties(): array
    {
        $properties = $this->loadProperties();

        if ($this->propertyType !== '') {
            $matchSlugs = $this->getMatchingSlugs($this->propertyType);
            $properties = array_values(array_filter($properties, fn (array $p) => in_array($p['propertyTypeSlug'] ?? '', $matchSlugs, true)));
        }

        return $properties;
    }

    /**
     * Given a propertyType slug, returns all slugs that represent the same concept
     * across languages (matched by lowercase name).
     */
    private function getMatchingSlugs(string $slug): array
    {
        return $this->cache->get(
            'property_type_slugs_' . $slug,
            function (ItemInterface $item) use ($slug): array {
                $item->expiresAfter(300);

                $selected = $this->sanityService->query(
                    '*[_type == "propertyType" && slug.current == $slug][0]{ name }',
                    ['slug' => $slug]
                );

                if (empty($selected['name'])) {
                    return [$slug];
                }

                $allMatches = $this->sanityService->query(
                    '*[_type == "propertyType" && lower(name) == lower($name)]{ "slug": slug.current }',
                    ['name' => $selected['name']]
                );

                if (!is_array($allMatches)) {
                    return [$slug];
                }

                return array_map(fn ($t) => $t['slug'], $allMatches);
            }
        );
    }

    private function loadProperties(): array
    {
        return $this->propertiesCache ??= $this->fetchProperties();
    }

    protected function instantiateMap(): Map
    {
        $map = (new Map('default'))
            ->center(new Point(48.8566, 2.3522))
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

        // Filter properties with valid location within bounds
        $validProperties = [];
        $points = [];
        $bounds = $this->getActiveBounds();

        foreach ($properties as $property) {
            if (empty($property['location']['lat']) || empty($property['location']['lng'])) {
                continue;
            }

            $lat = $property['location']['lat'];
            $lng = $property['location']['lng'];

            if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
                continue;
            }

            $point = new Point($lat, $lng);
            $points[] = $point;
            $validProperties[] = $property;
        }

        if (empty($points)) {
            return;
        }

        // Build a lookup: "lat,lng" => list of properties at that point
        $pointToProperties = [];
        foreach ($validProperties as $i => $property) {
            $key = $points[$i]->getLatitude() . ',' . $points[$i]->getLongitude();
            $pointToProperties[$key][] = $property;
        }

        $algorithm = new GridClusteringAlgorithm();
        $clusters = $algorithm->cluster($points, $this->zoom);

        foreach ($clusters as $cluster) {
            $center = $cluster->getCenter();

            if ($cluster->count() === 1) {
                // Single marker: find the property for this point
                $singlePoint = $cluster->getPoints()[0];
                $key = $singlePoint->getLatitude() . ',' . $singlePoint->getLongitude();
                $property = $pointToProperties[$key][0] ?? null;

                if ($property) {
                    $this->addPropertyMarker($map, $property);
                }
            } else {
                // Cluster marker: collect all propertyIds in this cluster
                $clusterPropertyIds = [];
                foreach ($cluster->getPoints() as $point) {
                    $key = $point->getLatitude() . ',' . $point->getLongitude();
                    foreach ($pointToProperties[$key] ?? [] as $p) {
                        $clusterPropertyIds[] = $p['_id'];
                    }
                }
                $this->addClusterMarker($map, $center, $cluster->count(), $clusterPropertyIds);
            }
        }
    }

    private function addPropertyMarker(Map $map, array $property): void
    {
        $label = !empty($property['priceOnRequest'])
            ? 'Sur demande'
            : (!empty($property['monthlyRent'])
                ? number_format($property['monthlyRent'], 0, ',', ' ') . ' €'
                : 'Sur demande');

        $charWidth = 8;
        $padding = 8;
        $svgWidth = (int) (mb_strlen($label) * $charWidth) + $padding * 2;
        $svgHeight = 30;

        $cx = (int) ($svgWidth / 2);
        $cy = (int) ($svgHeight / 2);

        $icon = Icon::svg(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                <defs>
                    <filter id="s" x="-20%%" y="-20%%" width="140%%" height="160%%">
                        <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#00000018"/>
                    </filter>
                </defs>
                <rect x="0.5" y="0.5" width="%d" height="%d" rx="15" fill="white" stroke="#e5e7eb" stroke-width="1" filter="url(#s)"/>
                <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="600" fill="#111827">%s</text>
            </svg>',
            $svgWidth + 1, $svgHeight + 1,
            $svgWidth - 1, $svgHeight - 1,
            $cx, $cy,
            $label,
        ));

        $hoverSvg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                <defs>
                    <filter id="s" x="-20%%" y="-20%%" width="140%%" height="160%%">
                        <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#00000030"/>
                    </filter>
                </defs>
                <rect x="0.5" y="0.5" width="%d" height="%d" rx="15" fill="#71172e" stroke="#71172e" stroke-width="1" filter="url(#s)"/>
                <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="600" fill="white">%s</text>
            </svg>',
            $svgWidth + 1, $svgHeight + 1,
            $svgWidth - 1, $svgHeight - 1,
            $cx, $cy,
            $label,
        );

        $map->addMarker(new Marker(
            position: new Point($property['location']['lat'], $property['location']['lng']),
            title: $property['address']['street'] ?? $property['title'] ?? '',
            icon: $icon,
            extra: ['hoverSvg' => $hoverSvg, 'propertyId' => $property['_id']],
            infoWindow: new InfoWindow(
                headerContent: '<b>' . ($property['address']['city'] ?? 'Paris') . '</b>',
                content: $property['title'] ?? '',
            ),
        ));
    }

    private function addClusterMarker(Map $map, Point $center, int $count, array $propertyIds = []): void
    {
        $label = (string) $count;
        $size = $count < 10 ? 46 : ($count < 100 ? 54 : 62);

        $icon = Icon::svg(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                <circle cx="%d" cy="%d" r="%d" fill="#e5e7eb" opacity="0.5"/>
                <circle cx="%d" cy="%d" r="%d" fill="#f3f4f6"/>
                <circle cx="%d" cy="%d" r="%d" fill="white"/>
                <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="700" fill="#111827">%s</text>
            </svg>',
            $size, $size,
            $size / 2, $size / 2, $size / 2 - 1,     // outer circle - gris léger
            $size / 2, $size / 2, (int) ($size * 0.4), // middle circle - gris clair
            $size / 2, $size / 2, (int) ($size * 0.3), // inner circle - blanc
            $size / 2, $size / 2,                     // text position
            $label,
        ));

        $map->addMarker(new Marker(
            position: $center,
            title: $count . ' biens',
            icon: $icon,
            extra: ['isCluster' => true, 'count' => $count, 'propertyIds' => $propertyIds],
        ));
    }

    private function getActiveBounds(): array
    {
        // Use LiveProp bounds if set (user has interacted with the map), otherwise default
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

    private function fetchProperties(): array
    {
        return $this->cache->get(
            'marketplace_properties_' . $this->locale,
            function (ItemInterface $item): array {
                $item->expiresAfter(300); // 5 minutes

                $results = $this->sanityService->query(
                    '*[_type == "property"] | order(_createdAt desc) {
                        _id,
                        "createdAt": _createdAt,
                        "updatedAt": _updatedAt,
                        uniqueId,
                        title,
                        shortDescription,
                        surface,
                        rooms,
                        bedrooms,
                        bathrooms,
                        "monthlyRent": rents.monthlyRent,
                        "priceOnRequest": rents.priceOnRequest,
                        "chargesIncludes": chargesIncludes,
                        "showCategoryOnCard": showCategoryOnCard,
                        currency,
                        status,
                        leaseType,
                        "listingTypeName": listingType->name,
                        longTerm,
                        midTerm,
                        categoryFlags,
                        "slug": slug.current,
                        "address": address{city, postalCode, street, number},
                        "mainPhoto": {
                            "url": mainPhoto.asset->url,
                            "alt": mainPhoto.alt
                        },
                        "photos": photos[0..3]{
                            "url": asset->url,
                            "alt": alt
                        },
                        availableDate,
                        "agentPhoto": agent->photo.asset->url,
                        "photoCount": count(photos),
                        "categoryName": categories[0]->name,
                        "location": location{lat, lng},
                        "elevator": equipment.elevator,
                        "furnished": main.furnished,
                        "bedroomsLabel": main.bedrooms,
                        "squareMeters": main.squareMeters,
                        "propertyTypeName": propertyType->name,
                        "propertyTypeSlug": propertyType->slug.current,
                        "propertyTypeLang": propertyType->language
                    }'
                );

                if (!is_array($results)) {
                    return [];
                }

                $seen = [];
                $deduped = [];
                foreach ($results as $property) {
                    $isDraft = str_starts_with($property['_id'], 'drafts.');
                    $baseId = $isDraft ? substr($property['_id'], 7) : $property['_id'];

                    if (!isset($seen[$baseId])) {
                        $seen[$baseId] = true;
                        $deduped[] = $property;
                    } elseif (!$isDraft) {
                        foreach ($deduped as $k => $p) {
                            if (str_replace('drafts.', '', $p['_id']) === $baseId) {
                                $deduped[$k] = $property;
                                break;
                            }
                        }
                    }
                }

                return $deduped;
            }
        );
    }
}
