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
        1 => [48.8606, 2.3376],
        2 => [48.8682, 2.3417],
        3 => [48.8630, 2.3601],
        4 => [48.8550, 2.3578],
        5 => [48.8443, 2.3500],
        6 => [48.8488, 2.3325],
        7 => [48.8567, 2.3127],
        8 => [48.8718, 2.3119],
        9 => [48.8769, 2.3372],
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

    /**
     * Well-known neighbourhood label per arrondissement, shown next to the number
     * in the filter panel (e.g. "1st · Louvre"). Bilingual only where it differs.
     *
     * @var array<int, array{fr: string, en: string}>
     */
    public const NAMES = [
        1 => ['fr' => 'Louvre', 'en' => 'Louvre'],
        2 => ['fr' => 'Bourse', 'en' => 'Bourse'],
        3 => ['fr' => 'Le Marais', 'en' => 'Le Marais'],
        4 => ['fr' => 'Hôtel de Ville', 'en' => 'Hôtel de Ville'],
        5 => ['fr' => 'Quartier latin', 'en' => 'Latin Quarter'],
        6 => ['fr' => 'Saint-Germain', 'en' => 'Saint-Germain'],
        7 => ['fr' => 'Tour Eiffel', 'en' => 'Eiffel Tower'],
        8 => ['fr' => 'Champs-Élysées', 'en' => 'Champs-Élysées'],
        9 => ['fr' => 'Opéra', 'en' => 'Opéra'],
        10 => ['fr' => 'Gare du Nord', 'en' => 'Gare du Nord'],
        11 => ['fr' => 'Bastille', 'en' => 'Bastille'],
        12 => ['fr' => 'Bercy', 'en' => 'Bercy'],
        13 => ['fr' => 'Quartier chinois', 'en' => 'Chinatown'],
        14 => ['fr' => 'Montparnasse', 'en' => 'Montparnasse'],
        15 => ['fr' => 'Beaugrenelle', 'en' => 'Beaugrenelle'],
        16 => ['fr' => 'Trocadéro', 'en' => 'Trocadéro'],
        17 => ['fr' => 'Batignolles', 'en' => 'Batignolles'],
        18 => ['fr' => 'Montmartre', 'en' => 'Montmartre'],
        19 => ['fr' => 'Parc des Buttes', 'en' => 'Parc des Buttes'],
        20 => ['fr' => 'Belleville', 'en' => 'Belleville'],
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
        if (null !== $arrondissement && isset(self::CENTERS[$arrondissement])) {
            return self::CENTERS[$arrondissement];
        }

        return self::DEFAULT_CENTER;
    }

    public static function defaultZoom(?int $arrondissement): int
    {
        return (null !== $arrondissement && isset(self::CENTERS[$arrondissement])) ? 14 : 12;
    }

    /**
     * Best-effort arrondissement for a Paris coordinate: the one whose center is
     * closest. Used as a fallback when a picked address carries no postal code
     * (e.g. a street spanning several arrondissements, like Rue de Rivoli).
     */
    public static function nearest(float $lat, float $lng): int
    {
        $best = 1;
        $bestDistance = \PHP_FLOAT_MAX;
        foreach (self::CENTERS as $arrondissement => [$centerLat, $centerLng]) {
            $distance = (($lat - $centerLat) ** 2) + (($lng - $centerLng) ** 2);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $arrondissement;
            }
        }

        return $best;
    }

    /** Neighbourhood label for the given arrondissement and locale. */
    public static function name(int $arrondissement, string $locale = 'fr'): string
    {
        return self::NAMES[$arrondissement][$locale] ?? self::NAMES[$arrondissement]['fr'] ?? '';
    }
}
