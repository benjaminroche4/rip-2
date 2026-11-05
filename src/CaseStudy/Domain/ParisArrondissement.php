<?php

namespace App\CaseStudy\Domain;

/**
 * Centre of each Paris arrondissement, where a case study map puts its area when the editor
 * did not fine-tune it. Residential centres: the 12th and 16th leave out the Bois de Vincennes
 * and the Bois de Boulogne.
 */
final class ParisArrondissement
{
    /** @var array<int, array{float, float}> arrondissement => [latitude, longitude] */
    private const CENTRES = [
        1 => [48.8625, 2.3363],
        2 => [48.8683, 2.3428],
        3 => [48.8630, 2.3601],
        4 => [48.8543, 2.3576],
        5 => [48.8445, 2.3497],
        6 => [48.8491, 2.3326],
        7 => [48.8562, 2.3121],
        8 => [48.8727, 2.3125],
        9 => [48.8771, 2.3375],
        10 => [48.8761, 2.3607],
        11 => [48.8590, 2.3800],
        12 => [48.8400, 2.3880],
        13 => [48.8283, 2.3623],
        14 => [48.8292, 2.3266],
        15 => [48.8401, 2.2930],
        16 => [48.8603, 2.2756],
        17 => [48.8873, 2.3067],
        18 => [48.8925, 2.3484],
        19 => [48.8871, 2.3848],
        20 => [48.8634, 2.4012],
    ];

    /** @return array{float, float}|null [latitude, longitude], null outside 1-20 */
    public static function centre(int $arrondissement): ?array
    {
        return self::CENTRES[$arrondissement] ?? null;
    }

    /**
     * A spot inside the arrondissement, near its centre, picked from a seed (facts shared by the FR
     * and EN versions of a case study): each case study gets its own place, the same on every visit,
     * and two case studies of one arrondissement do not stack up. At most about 400 m from the
     * centre, so it stays inside the arrondissement.
     *
     * @return array{float, float}|null [latitude, longitude], null outside 1-20
     */
    public static function spotNear(int $arrondissement, string $seed): ?array
    {
        $centre = self::centre($arrondissement);
        if (null === $centre) {
            return null;
        }

        $hash = crc32($seed);
        $latOffset = (($hash & 0xFFFF) / 0xFFFF - 0.5) * 2 * 0.0035;     // about ±390 m
        $lngOffset = ((($hash >> 16) & 0xFFFF) / 0xFFFF - 0.5) * 2 * 0.005; // about ±370 m

        return [round($centre[0] + $latOffset, 5), round($centre[1] + $lngOffset, 5)];
    }

    /** Arrondissement number out of a written district ("Paris 16e", "Paris 16th", "Paris 1er"), null if none. */
    public static function fromText(string $district): ?int
    {
        if (1 !== preg_match('/\b(\d{1,2})\s*(?:e|er|ème|st|nd|rd|th)?\b/u', $district, $match)) {
            return null;
        }
        $number = (int) $match[1];

        return $number >= 1 && $number <= 20 ? $number : null;
    }
}
