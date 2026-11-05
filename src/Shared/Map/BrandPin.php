<?php

namespace App\Shared\Map;

use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\Icon\SvgIcon;

/**
 * The site's map icons, shared by every Google map of the site: the address pin (primary drop
 * with a house) and the area badge.
 */
final class BrandPin
{
    private const SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="39" height="50" viewBox="0 0 44 56">
            <path d="M22 0C9.85 0 0 9.85 0 22c0 16.5 22 34 22 34s22-17.5 22-34C44 9.85 34.15 0 22 0Z" fill="#71172e"/>
            <circle cx="22" cy="22" r="13" fill="white"/>
            <path d="M22 14.5a2 2 0 0 0-1.28.46l-5.44 4.53A2 2 0 0 0 14.57 21v7.5a1.5 1.5 0 0 0 1.5 1.5h3v-4.5a1.5 1.5 0 0 1 1.5-1.5h2.86a1.5 1.5 0 0 1 1.5 1.5V30h3a1.5 1.5 0 0 0 1.5-1.5V21a2 2 0 0 0-.71-1.51l-5.44-4.53A2 2 0 0 0 22 14.5Z" fill="#71172e" stroke="#71172e" stroke-width="0.5" stroke-linejoin="round"/>
        </svg>
        SVG;

    /**
     * Round badge (primary disc, white house) for the centre of an area: it marks a zone, not an address.
     * Google anchors marker icons at their bottom centre: the SVG box stops at the disc's centre and
     * overflow="visible" draws the lower half, so the disc is centred on the point.
     */
    private const AREA_SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="20" viewBox="0 0 40 20" overflow="visible" style="overflow:visible">
            <circle cx="20" cy="20" r="18" fill="#71172e" stroke="white" stroke-width="3"/>
            <path d="M20 12.5a1.8 1.8 0 0 0-1.15.41l-4.9 4.08a1.8 1.8 0 0 0-.65 1.38v6.75a1.35 1.35 0 0 0 1.35 1.35h2.7v-4.05a1.35 1.35 0 0 1 1.35-1.35h2.6a1.35 1.35 0 0 1 1.35 1.35v4.05h2.7a1.35 1.35 0 0 0 1.35-1.35v-6.75a1.8 1.8 0 0 0-.65-1.38l-4.9-4.08A1.8 1.8 0 0 0 20 12.5Z" fill="white"/>
        </svg>
        SVG;

    public static function icon(): SvgIcon
    {
        return Icon::svg(self::SVG);
    }

    public static function areaIcon(): SvgIcon
    {
        return Icon::svg(self::AREA_SVG);
    }
}
