<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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
                $item->expiresAfter(300); // 5 minutes

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
                        price,
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
                        "photoCount": count(photos)
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
            ->center(new Point(45.7534031, 4.8295061))
            ->zoom(6)

            ->addMarker(new Marker(
                position: new Point(45.7534031, 4.8295061),
                title: 'Lyon',
                infoWindow: new InfoWindow(
                    content: '<p>Thank you <a href="https://github.com/Kocal">@Kocal</a> for this component!</p>',
                )
            ));

        return $this->render('public/marketplace/list.html.twig', [
            'properties' => $properties,
            'totalCount' => count($properties),
            'map' => $map,
        ]);
    }
}
