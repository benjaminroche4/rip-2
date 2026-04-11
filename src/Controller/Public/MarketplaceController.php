<?php

namespace App\Controller\Public;

use App\Marketplace\Repository\PropertyRepository;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly PropertyRepository $propertyRepository,
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
            'schemaProperties' => $this->propertyRepository->findForSchema($_locale, 12),
            'schemaPropertiesTotal' => $this->propertyRepository->countAvailable($_locale),
        ]);
    }

    #[Route(
        path: '/_marketplace/property-card/{locale}/{id}',
        name: 'app_property_card_fragment',
        requirements: ['locale' => 'fr|en', 'id' => '[A-Za-z0-9._-]+'],
        methods: ['GET'],
    )]
    public function propertyCardFragment(string $locale, string $id): Response
    {
        $property = $this->propertyRepository->findOneById($id, $locale);
        if ($property === null) {
            return new Response('', 404);
        }

        $response = $this->render('components/MarketplaceSearch/Card.html.twig', [
            'property' => $property,
            'locale' => $locale,
            'compact' => true,
        ]);

        // Cache 5 min côté HTTP : la card est immutable tant que la donnée Sanity ne change pas.
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);

        return $response;
    }
}
