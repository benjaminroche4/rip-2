<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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
                'section' => 'properties',
                'priority' => 0.9,
                'changefreq' => UrlConcrete::CHANGEFREQ_DAILY,
                'lastmod' => new \DateTime('2026-04-02'),
            ],
        ]
    )]
    public function list(string $_locale): Response
    {
        return $this->render('public/marketplace/list.html.twig', [
            'locale' => $_locale,
            'schemaProperties' => $this->fetchSchemaProperties($_locale),
            'schemaPropertiesTotal' => $this->fetchSchemaPropertiesCount($_locale),
        ]);
    }

    /**
     * Fetch a lightweight property list for JSON-LD schema.org markup.
     * Cached so it shares state with the LiveComponent's render path.
     */
    private function fetchSchemaProperties(string $locale): array
    {
        return $this->cache->get(
            'marketplace_schema_properties_' . $locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(300);

                $results = $this->sanityService->query(
                    '*[_type == "property" && language == $lang && status != "rented"] | order(_createdAt desc) [0..49] {
                        _id,
                        title,
                        "slug": slug.current,
                        "monthlyRent": rents.monthlyRent,
                        "priceOnRequest": rents.priceOnRequest,
                        rooms,
                        bedrooms,
                        "squareMeters": main.squareMeters,
                        status,
                        "address": address{city, postalCode},
                        "image": mainPhoto.asset->url,
                        "propertyTypeName": propertyType->name
                    }',
                    ['lang' => $locale]
                );

                return is_array($results) ? $results : [];
            }
        );
    }

    /**
     * Returns the total number of available properties (used for schema.org numberOfItems).
     * Cheap count() query, cached separately.
     */
    private function fetchSchemaPropertiesCount(string $locale): int
    {
        return $this->cache->get(
            'marketplace_schema_properties_count_' . $locale,
            function (ItemInterface $item) use ($locale): int {
                $item->expiresAfter(300);

                $result = $this->sanityService->query(
                    'count(*[_type == "property" && language == $lang && status != "rented"])',
                    ['lang' => $locale]
                );

                return is_int($result) ? $result : 0;
            }
        );
    }
}
