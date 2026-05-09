<?php

namespace App\Marketplace\Map;

use App\Marketplace\Domain\Property;
use App\Marketplace\Reference\ParisArrondissements;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Cluster\GridClusteringAlgorithm;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Point;

/**
 * Builds the marketplace map: initial Map instance + marker placement
 * (with grid clustering and bounds filtering).
 *
 * Extracted from MarketplaceSearch LiveComponent so the map orchestration
 * can be unit-tested without spinning up a Live component.
 */
final class MapBuilder
{
    public function __construct(
        private readonly MarkerBuilder $markerBuilder,
    ) {
    }

    public function buildMap(?int $arrondissement, float $zoom): Map
    {
        [$lat, $lng] = ParisArrondissements::getCenter($arrondissement);

        return (new Map('default'))
            ->center(new Point($lat, $lng))
            ->zoom($zoom)
            ->minZoom(9)
            ->maxZoom(22)
            ->options(new GoogleOptions(
                gestureHandling: GestureHandling::GREEDY,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ));
    }

    /**
     * Adds clustered markers for the given properties to the map. Properties
     * outside the visible bounds (or without coordinates) are skipped.
     *
     * If $spideredPropertyIds matches a cluster's full set of IDs, that cluster
     * is rendered as a fan of individual offset markers (spider-fy) instead.
     *
     * @param array<int, Property>                                        $properties
     * @param array{south: float, north: float, west: float, east: float} $bounds
     * @param array<int, string>                                          $spideredPropertyIds
     */
    public function addMarkers(Map $map, array $properties, array $bounds, float $zoom, string $locale, array $spideredPropertyIds = []): void
    {
        $points = [];
        $pointToProperties = [];

        foreach ($properties as $property) {
            $lat = $property->location['lat'] ?? null;
            $lng = $property->location['lng'] ?? null;

            if (null === $lat || null === $lng) {
                continue;
            }
            if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
                continue;
            }

            $key = $lat.','.$lng;
            // One Point per unique coordinate — properties sharing exact lat/lng must not
            // produce duplicate Points, otherwise the cluster collection step double-counts.
            if (!isset($pointToProperties[$key])) {
                $points[] = new Point($lat, $lng);
                $pointToProperties[$key] = [];
            }
            $pointToProperties[$key][] = $property;
        }

        if (empty($points)) {
            return;
        }

        $clusters = (new GridClusteringAlgorithm())->cluster($points, $zoom);

        $sortedSpidered = $spideredPropertyIds;
        sort($sortedSpidered);

        foreach ($clusters as $cluster) {
            $clusterProperties = [];
            foreach ($cluster->getPoints() as $point) {
                $key = $point->getLatitude().','.$point->getLongitude();
                foreach ($pointToProperties[$key] ?? [] as $p) {
                    $clusterProperties[] = $p;
                }
            }

            $effectiveCount = count($clusterProperties);
            if (0 === $effectiveCount) {
                continue;
            }

            if (1 === $effectiveCount) {
                $map->addMarker($this->markerBuilder->buildPropertyMarker($clusterProperties[0], $locale));
                continue;
            }

            $clusterPropertyIds = array_map(static fn (Property $p) => $p->id, $clusterProperties);

            $sortedClusterIds = $clusterPropertyIds;
            sort($sortedClusterIds);

            if (!empty($spideredPropertyIds) && $sortedClusterIds === $sortedSpidered) {
                $this->spiderfyCluster($map, $cluster->getCenter(), $clusterProperties, $zoom, $locale);
                continue;
            }

            $map->addMarker($this->markerBuilder->buildClusterMarker(
                $cluster->getCenter(),
                $effectiveCount,
                $clusterPropertyIds,
                $locale,
            ));
        }
    }

    /**
     * Renders cluster properties in a circular fan around the cluster center,
     * with a pixel-equivalent radius adapted to the current zoom.
     *
     * @param array<int, Property> $clusterProperties
     */
    private function spiderfyCluster(Map $map, Point $center, array $clusterProperties, float $zoom, string $locale): void
    {
        $count = count($clusterProperties);
        if (0 === $count) {
            return;
        }

        // ~70px radius converted to degrees at the current zoom (Web Mercator: 256px tile = 360°/2^zoom).
        $pixelRadius = 70;
        $radius = $pixelRadius * 360 / (256 * 2 ** $zoom);
        $latRad = deg2rad($center->getLatitude());
        $lngScale = max(cos($latRad), 0.01);

        foreach ($clusterProperties as $i => $property) {
            $angle = 2 * M_PI * $i / $count - M_PI_2; // start at top
            $position = new Point(
                $center->getLatitude() + $radius * sin($angle),
                $center->getLongitude() + $radius * cos($angle) / $lngScale,
            );
            $map->addMarker($this->markerBuilder->buildPropertyMarker($property, $locale, $position));
        }
    }

    /**
     * Returns the active visible bounds: user-driven if all four are provided,
     * default Paris-area otherwise.
     *
     * @return array{south: float, north: float, west: float, east: float}
     */
    public function resolveBounds(?float $south, ?float $north, ?float $west, ?float $east): array
    {
        if (null !== $south && null !== $north && null !== $west && null !== $east) {
            return [
                'south' => $south,
                'north' => $north,
                'west' => $west,
                'east' => $east,
            ];
        }

        return ParisArrondissements::DEFAULT_BOUNDS;
    }
}
