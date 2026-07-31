<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Contact\Domain\ParisDistricts;

/**
 * Self-contained SVG map of Paris + petite couronne with the requested
 * districts highlighted, for the contact PDF (no JS, no external tiles:
 * DocRaptor renders it as plain vector graphics).
 */
final class DistrictMapSvg
{
    private const WIDTH = 720.0;

    /**
     * @param list<string> $selectedCodes
     */
    public function render(array $selectedCodes): string
    {
        $paths = ParisDistricts::PATHS;

        [$minLat, $maxLat, $minLng, $maxLng] = $this->bounds($paths);
        // Equirectangular projection with latitude correction so shapes
        // keep their real proportions.
        $xScale = cos(deg2rad(($minLat + $maxLat) / 2));
        $spanX = ($maxLng - $minLng) * $xScale;
        $spanY = $maxLat - $minLat;
        $scale = self::WIDTH / $spanX;
        $height = $spanY * $scale;

        $project = fn (float $lat, float $lng): array => [
            ($lng - $minLng) * $xScale * $scale,
            ($maxLat - $lat) * $scale,
        ];

        $shapes = '';
        $labels = '';
        foreach ($paths as $code => $points) {
            $selected = \in_array((string) $code, $selectedCodes, true);
            $isDept = \strlen((string) $code) <= 2;

            $d = '';
            $sumX = $sumY = 0.0;
            foreach ($points as $i => [$lat, $lng]) {
                [$x, $y] = $project($lat, $lng);
                $d .= ($i > 0 ? ' L' : 'M').round($x, 1).' '.round($y, 1);
                $sumX += $x;
                $sumY += $y;
            }
            $d .= ' Z';

            $fill = $selected ? '#71172e' : ($isDept ? '#fafafa' : '#ffffff');
            $fillOpacity = $selected ? '0.85' : '1';
            $stroke = $selected ? '#71172e' : '#c8c8c8';
            $shapes .= \sprintf(
                '<path d="%s" fill="%s" fill-opacity="%s" stroke="%s" stroke-width="1.2" stroke-linejoin="round"/>',
                $d,
                $fill,
                $fillOpacity,
                $stroke,
            );

            // Label the arrondissements (the couronne shapes are too big
            // for a centroid label to read well at this size).
            if (!$isDept || $selected) {
                $cx = $sumX / \count($points);
                $cy = $sumY / \count($points);
                $labels .= \sprintf(
                    '<text x="%s" y="%s" text-anchor="middle" dominant-baseline="middle" font-size="%s" font-weight="%s" fill="%s">%s</text>',
                    round($cx, 1),
                    round($cy, 1),
                    $isDept ? '18' : '13',
                    $selected ? '700' : '400',
                    $selected ? '#ffffff' : '#9ca3af',
                    htmlspecialchars($isDept ? (string) $code : (string) $code, \ENT_QUOTES),
                );
            }
        }

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img">%s%s</svg>',
            self::WIDTH,
            round($height, 1),
            $shapes,
            $labels,
        );
    }

    /**
     * @param array<string, list<array{0: float, 1: float}>> $paths
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function bounds(array $paths): array
    {
        $minLat = $minLng = \INF;
        $maxLat = $maxLng = -\INF;
        foreach ($paths as $points) {
            foreach ($points as [$lat, $lng]) {
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
            }
        }

        return [$minLat, $maxLat, $minLng, $maxLng];
    }
}
