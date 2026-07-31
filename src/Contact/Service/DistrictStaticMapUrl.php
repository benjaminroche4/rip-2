<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Domain\ParisDistricts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Google Static Maps URL with the requested districts highlighted, for
 * client-facing emails (same map provider as the property-listing emails).
 * Polygons are polyline-encoded to stay well under the URL size limit;
 * the API auto-fits the viewport to the drawn paths.
 */
final readonly class DistrictStaticMapUrl
{
    private const FILL = '0x71172e35';
    private const STROKE = '0x71172eCC';

    /** Same Cloud Map ID as the marketplace map (config/packages/ux_map.yaml). */
    private const MAP_ID = '17a6371e43c53ecdecba3794';

    public function __construct(
        #[Autowire('%env(GOOGLE_MAPS_API_KEY)%')]
        private string $apiKey,
    ) {
    }

    /**
     * @param list<string> $selectedCodes
     */
    public function build(array $selectedCodes, string $language = 'fr'): ?string
    {
        $paths = [];
        foreach ($selectedCodes as $code) {
            if (!isset(ParisDistricts::PATHS[$code])) {
                continue;
            }
            $paths[] = \sprintf(
                'fillcolor:%s|color:%s|weight:2|enc:%s',
                self::FILL,
                self::STROKE,
                $this->encodePolyline(ParisDistricts::PATHS[$code]),
            );
        }

        if ([] === $paths) {
            return null;
        }

        $query = http_build_query([
            'size' => '560x260',
            'scale' => '2',
            'language' => $language,
            'map_id' => self::MAP_ID,
            'key' => $this->apiKey,
        ]);
        foreach ($paths as $path) {
            $query .= '&path='.rawurlencode($path);
        }

        return 'https://maps.googleapis.com/maps/api/staticmap?'.$query;
    }

    /**
     * Google polyline encoding (closes the ring back to the first point).
     *
     * @param list<array{0: float, 1: float}> $points [lat, lng]
     */
    private function encodePolyline(array $points): string
    {
        $points[] = $points[0];

        $encoded = '';
        $prevLat = $prevLng = 0;
        foreach ($points as [$lat, $lng]) {
            $latE5 = (int) round($lat * 1e5);
            $lngE5 = (int) round($lng * 1e5);
            $encoded .= $this->encodeNumber($latE5 - $prevLat).$this->encodeNumber($lngE5 - $prevLng);
            $prevLat = $latE5;
            $prevLng = $lngE5;
        }

        return $encoded;
    }

    private function encodeNumber(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunk = '';
        while ($value >= 0x20) {
            $chunk .= \chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.\chr($value + 63);
    }
}
