<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Centralized color palette for admin chart series. Each palette entry is a
 * {color, fillColor} pair: the controllers spread these into the series
 * payload consumed by chart_controller.js / chart_bars_controller.js. Keeps
 * the dashboard and payments views typographically aligned without forcing
 * each controller to remember the matching rgba opacity.
 */
final class ChartPalette
{
    /** @var array{color: string, fillColor: string} */
    public const SKY = ['color' => '#0ea5e9', 'fillColor' => 'rgba(14, 165, 233, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const PRIMARY = ['color' => '#71172e', 'fillColor' => 'rgba(113, 23, 46, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const BLUE = ['color' => '#2563eb', 'fillColor' => 'rgba(37, 99, 235, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const PURPLE = ['color' => '#9333ea', 'fillColor' => 'rgba(147, 51, 234, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const GREEN = ['color' => '#16a34a', 'fillColor' => 'rgba(22, 163, 74, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const PINK = ['color' => '#ec4899', 'fillColor' => 'rgba(236, 72, 153, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const INDIGO = ['color' => '#6366f1', 'fillColor' => 'rgba(99, 102, 241, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const EMERALD = ['color' => '#10b981', 'fillColor' => 'rgba(16, 185, 129, 0.1)'];

    /** @var array{color: string, fillColor: string} */
    public const TEAL = ['color' => '#14b8a6', 'fillColor' => 'rgba(20, 184, 166, 0.1)'];
}
