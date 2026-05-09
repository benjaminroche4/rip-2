<?php

declare(strict_types=1);

namespace App\Admin\Service;

/**
 * Shared KPI card payload builder for admin pages (Dashboard, Payments…).
 *
 * Compares $current to $previous and emits a uniform structure the Twig
 * templates can render with a single component-style block: title, period,
 * value, raw previous, signed delta percent, trend marker.
 */
final class AdminKpiBuilder
{
    /**
     * Builds a KPI card payload. When $previous is null, the card stays
     * neutral (no comparison data available — e.g. periods that fall
     * outside our reporting window).
     *
     * $currentLabel/$previousLabel let callers override the bar labels
     * (e.g. "Mai" / "Avril" instead of the generic "Actuel" / "Précédent").
     * When null, templates fall back to the shared translation keys.
     *
     * @return array{title:string, period:string, value:int, previous:?int, deltaPercent:?int, trend:'up'|'down'|'neutral', currentLabel:?string, previousLabel:?string}
     */
    public function build(
        string $title,
        string $period,
        int $current,
        ?int $previous,
        ?string $currentLabel = null,
        ?string $previousLabel = null,
    ): array {
        $trend = 'neutral';
        $deltaPercent = null;

        if (null !== $previous) {
            if ($previous > 0) {
                $deltaPercent = (int) round((($current - $previous) / $previous) * 100);
                $trend = $deltaPercent > 0 ? 'up' : ($deltaPercent < 0 ? 'down' : 'neutral');
            } elseif ($current > 0) {
                // 0 → N: real growth, but % is undefined. Templates show
                // an arrow without a percentage in that case.
                $trend = 'up';
            }
        }

        return [
            'title' => $title,
            'period' => $period,
            'value' => $current,
            'previous' => $previous,
            'deltaPercent' => $deltaPercent,
            'trend' => $trend,
            'currentLabel' => $currentLabel,
            'previousLabel' => $previousLabel,
        ];
    }
}
