<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Visit\Domain\GeoPoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves a free-text address to coordinates via the Google Geocoding API,
 * synchronously at visit creation (no worker on o2switch). A failure returns
 * null and must never block creating the visit — the pin just misses from
 * the day map.
 */
final class AddressGeocoder
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GOOGLE_MAPS_API_KEY')]
        private readonly string $apiKey = '',
    ) {
    }

    public function geocode(string $address): ?GeoPoint
    {
        $address = trim($address);
        $key = trim($this->apiKey);
        // The .env placeholder is a "#..." comment string — treat it as absent.
        if ('' === $address || '' === $key || str_starts_with($key, '#')) {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
                'query' => [
                    'address' => $address,
                    'region' => 'fr',
                    'language' => 'fr',
                    'key' => $key,
                ],
                'timeout' => 5,
            ])->toArray();
        } catch (\Throwable) {
            return null;
        }

        $location = $data['results'][0]['geometry']['location'] ?? null;
        if (!isset($location['lat'], $location['lng'])) {
            return null;
        }

        return new GeoPoint((float) $location['lat'], (float) $location['lng']);
    }
}
