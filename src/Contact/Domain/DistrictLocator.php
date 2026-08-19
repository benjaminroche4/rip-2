<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Locates a coordinate inside the Paris district outlines: ray-casting
 * against the simplified polygons of ParisDistricts::PATHS. Feeds the
 * "outside the searched areas" hint of the visit booking form.
 */
final class DistrictLocator
{
    /**
     * District code of the polygon containing the point, or null when the
     * point sits outside every outline (arrondissements are declared before
     * the petite couronne, so a Paris point always resolves to its
     * arrondissement first).
     */
    public static function locate(float $lat, float $lng): ?string
    {
        foreach (ParisDistricts::PATHS as $code => $ring) {
            if (self::contains($lat, $lng, $ring)) {
                // Les codes de petite couronne ('92'...) redeviennent des
                // clés int en PHP : on renvoie toujours une string.
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Even-odd ray casting: a horizontal ray from the point crosses the
     * ring's edges an odd number of times when the point is inside.
     *
     * @param list<array{0: float, 1: float}> $ring [lat, lng] outline
     */
    private static function contains(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $count = \count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $ring[$i];
            [$latJ, $lngJ] = $ring[$j];
            if (($lngI > $lng) !== ($lngJ > $lng)
                && $lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
