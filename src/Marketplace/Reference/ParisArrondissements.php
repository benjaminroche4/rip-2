<?php

namespace App\Marketplace\Reference;

/**
 * Approximate geographic centers for each Paris arrondissement (1–20).
 * Used to recenter the map when the user filters by arrondissement.
 */
final class ParisArrondissements
{
    /** @var array<int, array{0: float, 1: float}> */
    public const CENTERS = [
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

    /** Default fallback: Notre-Dame de Paris area. */
    public const DEFAULT_CENTER = [48.8566, 2.3522];

    /**
     * Default visible bounds for the marketplace map: Paris + petite couronne.
     */
    public const DEFAULT_BOUNDS = [
        'south' => 48.69,
        'north' => 49.01,
        'west' => 2.09,
        'east' => 2.67,
    ];

    /**
     * @return array{0: float, 1: float}
     */
    public static function getCenter(?int $arrondissement): array
    {
        if ($arrondissement !== null && isset(self::CENTERS[$arrondissement])) {
            return self::CENTERS[$arrondissement];
        }

        return self::DEFAULT_CENTER;
    }

    public static function defaultZoom(?int $arrondissement): int
    {
        return ($arrondissement !== null && isset(self::CENTERS[$arrondissement])) ? 14 : 12;
    }
}
