<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/** Latitude/longitude pair returned by the geocoder. */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }
}
