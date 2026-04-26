<?php

namespace App\Marketplace\Map;

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
    ) {}

    public function buildMap(?int $arrondissement, float $zoom): Map
    {
        [$lat, $lng] = ParisArrondissements::getCenter($arrondissement);

        return (new Map('default'))
            ->center(new Point($lat, $lng))
            ->zoom($zoom)
            ->minZoom(9)
            ->maxZoom(17)
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
     * @param array<int, array<string, mixed>> $properties
     * @param array{south: float, north: float, west: float, east: float} $bounds
     */
    public function addMarkers(Map $map, array $properties, array $bounds, float $zoom, string $locale): void
    {
        $points = [];
        $pointToProperties = [];

        foreach ($properties as $property) {
            $lat = $property['location']['lat'] ?? null;
            $lng = $property['location']['lng'] ?? null;

            if ($lat === null || $lng === null) {
                continue;
            }
            if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
                continue;
            }

            $point = new Point($lat, $lng);
            $key = $lat . ',' . $lng;
            $points[] = $point;
            $pointToProperties[$key][] = $property;
        }

        if (empty($points)) {
            return;
        }

        $clusters = (new GridClusteringAlgorithm())->cluster($points, $zoom);

        foreach ($clusters as $cluster) {
            if ($cluster->count() === 1) {
                $singlePoint = $cluster->getPoints()[0];
                $key = $singlePoint->getLatitude() . ',' . $singlePoint->getLongitude();
                $property = $pointToProperties[$key][0] ?? null;

                if ($property !== null) {
                    $map->addMarker($this->markerBuilder->buildPropertyMarker($property, $locale));
                }
                continue;
            }

            $clusterPropertyIds = [];
            foreach ($cluster->getPoints() as $point) {
                $key = $point->getLatitude() . ',' . $point->getLongitude();
                foreach ($pointToProperties[$key] ?? [] as $p) {
                    $clusterPropertyIds[] = $p['_id'];
                }
            }
            $map->addMarker($this->markerBuilder->buildClusterMarker(
                $cluster->getCenter(),
                $cluster->count(),
                $clusterPropertyIds,
                $locale,
            ));
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
        if ($south !== null && $north !== null && $west !== null && $east !== null) {
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
