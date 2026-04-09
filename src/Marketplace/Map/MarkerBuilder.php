<?php

namespace App\Marketplace\Map;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

/**
 * Builds Map Markers (single property + cluster) with their inline SVG icons.
 */
final class MarkerBuilder
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param array<string, mixed> $property
     */
    public function buildPropertyMarker(array $property, string $locale): Marker
    {
        $label = $this->buildPriceLabel($property, $locale);

        $charWidth = 8;
        $padding = 8;
        $svgWidth = (int) (mb_strlen($label) * $charWidth) + $padding * 2;
        $svgHeight = 30;
        $cx = (int) ($svgWidth / 2);
        $cy = (int) ($svgHeight / 2);

        $icon = Icon::svg($this->renderPriceSvg($svgWidth, $svgHeight, $cx, $cy, $label, false));
        $hoverSvg = $this->renderPriceSvg($svgWidth, $svgHeight, $cx, $cy, $label, true);

        return new Marker(
            position: new Point($property['location']['lat'], $property['location']['lng']),
            title: $property['address']['street'] ?? $property['title'] ?? '',
            icon: $icon,
            extra: ['hoverSvg' => $hoverSvg, 'propertyId' => $property['_id']],
        );
    }

    /**
     * @param array<int, string> $propertyIds
     */
    public function buildClusterMarker(Point $center, int $count, array $propertyIds, string $locale): Marker
    {
        $label = (string) $count;
        $size = $count < 10 ? 46 : ($count < 100 ? 54 : 62);

        $icon = Icon::svg(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                <circle cx="%d" cy="%d" r="%d" fill="#e5e7eb" opacity="0.5"/>
                <circle cx="%d" cy="%d" r="%d" fill="#f3f4f6"/>
                <circle cx="%d" cy="%d" r="%d" fill="white"/>
                <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="700" fill="#111827">%s</text>
            </svg>',
            $size, $size,
            $size / 2, $size / 2, $size / 2 - 1,
            $size / 2, $size / 2, (int) ($size * 0.4),
            $size / 2, $size / 2, (int) ($size * 0.3),
            $size / 2, $size / 2,
            $label,
        ));

        return new Marker(
            position: $center,
            title: $this->translator->trans('marketplace.list.card.map.cluster.label', ['%count%' => $count], null, $locale),
            icon: $icon,
            extra: ['isCluster' => true, 'count' => $count, 'propertyIds' => $propertyIds],
        );
    }

    /**
     * @param array<string, mixed> $property
     */
    private function buildPriceLabel(array $property, string $locale): string
    {
        $onRequest = $this->translator->trans('marketplace.list.card.map.marker.onRequest', [], null, $locale);

        if (!empty($property['priceOnRequest'])) {
            return $onRequest;
        }

        if (!empty($property['monthlyRent'])) {
            return number_format($property['monthlyRent'], 0, ',', ' ') . ' €';
        }

        return $onRequest;
    }

    private function renderPriceSvg(int $w, int $h, int $cx, int $cy, string $label, bool $hover): string
    {
        $fill = $hover ? '#71172e' : 'white';
        $stroke = $hover ? '#71172e' : '#e5e7eb';
        $textFill = $hover ? 'white' : '#111827';
        $shadow = $hover ? '#00000030' : '#00000018';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                <defs>
                    <filter id="s" x="-20%%" y="-20%%" width="140%%" height="160%%">
                        <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="%s"/>
                    </filter>
                </defs>
                <rect x="0.5" y="0.5" width="%d" height="%d" rx="15" fill="%s" stroke="%s" stroke-width="1" filter="url(#s)"/>
                <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="600" fill="%s">%s</text>
            </svg>',
            $w + 1, $h + 1,
            $shadow,
            $w - 1, $h - 1, $fill, $stroke,
            $cx, $cy, $textFill,
            $label,
        );
    }
}
