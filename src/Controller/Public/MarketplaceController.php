<?php

namespace App\Controller\Public;

use App\Data\ParisTransport;
use App\Marketplace\Repository\PropertyRepository;
use App\Twig\Extension\PropertyUrlExtension;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly PropertyRepository $propertyRepository,
        private readonly PropertyUrlExtension $propertyUrlExtension,
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
        path: [
            'fr' => '/{_locale}/nos-biens/{listingType}/{propertyType}/{city}/{district}/{slug}',
            'en' => '/{_locale}/our-properties/{listingType}/{propertyType}/{city}/{district}/{slug}',
        ],
        name: 'app_property_show',
        requirements: [
            'listingType' => '[a-z0-9-]+',
            'propertyType' => '[a-z0-9-]+',
            'city' => '[a-z0-9-]+',
            'district' => '[a-z0-9-]+',
            'slug' => '[a-z0-9-]+',
        ],
    )]
    public function show(string $slug, string $_locale): Response
    {
        $property = $this->propertyRepository->findOneBySlug($slug, $_locale);
        if ($property === null) {
            throw $this->createNotFoundException();
        }

        // Redirect 301 if SEO segments don't match (canonical URL)
        $canonicalPath = $this->propertyUrlExtension->propertyShowPath($property, $_locale);
        $currentPath = $this->container->get('request_stack')->getCurrentRequest()->getPathInfo();
        if ($currentPath !== $canonicalPath) {
            return $this->redirect($canonicalPath, 301);
        }

        return $this->render('public/marketplace/show.html.twig', [
            'property' => $property,
            'locale' => $_locale,
            'transportLines' => ParisTransport::LINES,
            'transportStationsByLine' => ParisTransport::STATIONS_BY_LINE,
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
