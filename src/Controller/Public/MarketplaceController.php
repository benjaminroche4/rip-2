<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly SanityService $sanityService,
        private readonly CacheInterface $cache,
    ) {}

    #[Route(
        path: [
            'fr' => '/{_locale}/nos-biens',
            'en' => '/{_locale}/our-properties',
        ],
        name: 'app_property',
        options: [
            'sitemap' => [
                'priority' => 0.9,
                'changefreq' => UrlConcrete::CHANGEFREQ_DAILY,
                'lastmod' => new \DateTime('2026-04-02'),
            ],
        ]
    )]
    public function list(string $_locale): Response
    {
        $properties = $this->cache->get(
            'marketplace_properties_' . $_locale,
            function (ItemInterface $item): array {
                //$item->expiresAfter(300); // 5 minutes

                $results = $this->sanityService->query(
                    '*[_type == "property"] | order(_createdAt desc) {
                        _id,
                        uniqueId,
                        title,
                        shortDescription,
                        surface,
                        rooms,
                        bedrooms,
                        bathrooms,
                        "monthlyRent": rents.monthlyRent,
                        currency,
                        status,
                        leaseType,
                        longTerm,
                        midTerm,
                        categoryFlags,
                        "slug": slug.current,
                        "address": address{city, postalCode, street, number},
                        "mainPhoto": photos[0].asset->url,
                        "mainPhotoAlt": photos[0].alt,
                        "photoCount": count(photos),
                        "location": location{lat, lng}
                    }'
                );

                if (!is_array($results)) {
                    return [];
                }

                // Deduplicate: prefer published over draft for same document
                $seen = [];
                $deduped = [];
                foreach ($results as $property) {
                    $isDraft = str_starts_with($property['_id'], 'drafts.');
                    $baseId = $isDraft ? substr($property['_id'], 7) : $property['_id'];

                    if (!isset($seen[$baseId])) {
                        $seen[$baseId] = true;
                        $deduped[] = $property;
                    } elseif (!$isDraft) {
                        // Replace existing draft entry with published version
                        foreach ($deduped as $k => $p) {
                            if (str_replace('drafts.', '', $p['_id']) === $baseId) {
                                $deduped[$k] = $property;
                                break;
                            }
                        }
                    }
                }

                return $deduped;
            }
        );

        $map = (new Map('default'))
            ->center(new Point(48.8566, 2.3522))
            ->zoom(12)
            ->minZoom(9)
            ->maxZoom(17)
            ->options(new GoogleOptions(
                gestureHandling: GestureHandling::GREEDY,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false
            ));


        // Paris + petite couronne (92, 93, 94) bounding box
        $bounds = ['south' => 48.69, 'north' => 49.01, 'west' => 2.09, 'east' => 2.67];

        foreach ($properties as $property) {
            if (empty($property['location']['lat']) || empty($property['location']['lng'])) {
                continue;
            }

            $lat = $property['location']['lat'];
            $lng = $property['location']['lng'];
            if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
                continue;
            }

            $label = !empty($property['monthlyRent'])
                ? number_format($property['monthlyRent'], 0, ',', ' ') . ' €'
                : 'Sur demande';

            $charWidth = 8;
            $padding = 8;
            $svgWidth = (int) (mb_strlen($label) * $charWidth) + $padding * 2;
            $svgHeight = 30;

            $cx = (int) ($svgWidth / 2);
            $cy = (int) ($svgHeight / 2);

            $icon = Icon::svg(sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                    <defs>
                        <filter id="s" x="-20%%" y="-20%%" width="140%%" height="160%%">
                            <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#00000018"/>
                        </filter>
                    </defs>
                    <rect x="0.5" y="0.5" width="%d" height="%d" rx="15" fill="white" stroke="#e5e7eb" stroke-width="1" filter="url(#s)"/>
                    <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="600" fill="#111827">%s</text>
                </svg>',
                $svgWidth + 1, $svgHeight + 1,
                $svgWidth - 1, $svgHeight - 1,
                $cx, $cy,
                $label,
            ));

            $hoverSvg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">
                    <defs>
                        <filter id="s" x="-20%%" y="-20%%" width="140%%" height="160%%">
                            <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#00000030"/>
                        </filter>
                    </defs>
                    <rect x="0.5" y="0.5" width="%d" height="%d" rx="15" fill="#71172e" stroke="#71172e" stroke-width="1" filter="url(#s)"/>
                    <text x="%d" y="%d" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="14" font-weight="600" fill="white">%s</text>
                </svg>',
                $svgWidth + 1, $svgHeight + 1,
                $svgWidth - 1, $svgHeight - 1,
                $cx, $cy,
                $label,
            );

            $map->addMarker(new Marker(
                position: new Point($property['location']['lat'], $property['location']['lng']),
                title: $property['address']['street'] ?? $property['title'] ?? '',
                icon: $icon,
                extra: ['hoverSvg' => $hoverSvg],
                infoWindow: new InfoWindow(
                    headerContent: '<b>Lyon</b>',
                    content: 'The French town in the historic Rhône-Alpes region, located at the junction of the Rhône and Saône rivers.'
                ),
            ));
        }

        return $this->render('public/marketplace/list.html.twig', [
            'properties' => $properties,
            'totalCount' => count($properties),
            'map' => $map,
        ]);
    }
}
