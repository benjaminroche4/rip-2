<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Visit\Domain\GeoPoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Walking route through the day's visits via the Google Routes API (the
 * Directions API it replaces is legacy and cannot be enabled on recent
 * Google Cloud projects). Called synchronously at page render (no worker on
 * o2switch), so the decoded path is cached: a day's route only changes when
 * a visit is added or moved, and the coordinates key the cache entry.
 * A failure returns null and must never break the page — the map then just
 * shows the pins without the route.
 */
final class WalkingRoutePlanner
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire(env: 'GOOGLE_MAPS_API_KEY')]
        private readonly string $apiKey = '',
    ) {
    }

    /**
     * @param list<GeoPoint> $stops chronological visit coordinates
     *
     * @return list<array{lat: float, lng: float}>|null decoded path, or null
     *                                                  when unavailable
     */
    public function route(array $stops): ?array
    {
        $key = trim($this->apiKey);
        // The .env placeholder is a "#..." comment string — treat it as absent.
        if (\count($stops) < 2 || '' === $key || str_starts_with($key, '#')) {
            return null;
        }

        $cacheKey = 'visit_walking_route.'.md5(implode('|', array_map(
            static fn (GeoPoint $stop): string => $stop->latitude.','.$stop->longitude,
            $stops,
        )));

        try {
            return $this->cache->get($cacheKey, function ($item) use ($stops, $key): array {
                $item->expiresAfter(self::CACHE_TTL);

                // Throwing on failure keeps the error OUT of the cache: the
                // next render retries instead of pinning a routeless day.
                return $this->fetchRoute($stops, $key);
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<GeoPoint> $stops
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function fetchRoute(array $stops, string $key): array
    {
        $toLatLng = static fn (GeoPoint $stop): array => [
            'location' => ['latLng' => ['latitude' => $stop->latitude, 'longitude' => $stop->longitude]],
        ];

        $data = $this->httpClient->request('POST', 'https://routes.googleapis.com/directions/v2:computeRoutes', [
            'headers' => [
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'routes.polyline.encodedPolyline',
            ],
            'json' => [
                'origin' => $toLatLng($stops[0]),
                'destination' => $toLatLng($stops[\count($stops) - 1]),
                'intermediates' => array_map($toLatLng, \array_slice($stops, 1, -1)),
                'travelMode' => 'WALK',
            ],
            'timeout' => 5,
        ])->toArray();

        $encoded = $data['routes'][0]['polyline']['encodedPolyline'] ?? null;
        if (!\is_string($encoded) || '' === $encoded) {
            throw new \RuntimeException('Routes API returned no polyline.');
        }

        return $this->decodePolyline($encoded);
    }

    /**
     * Standard Google encoded-polyline algorithm (precision 5).
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = \strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            foreach (['lat', 'lng'] as $coordinate) {
                $result = 0;
                $shift = 0;
                do {
                    $byte = \ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ('lat' === $coordinate) {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }

            $points[] = ['lat' => $lat / 1e5, 'lng' => $lng / 1e5];
        }

        return $points;
    }
}
