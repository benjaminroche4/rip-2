<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Repository\PropertyRepository;
use App\Marketplace\Twig\Extension\PropertyUrlExtension;
use App\Shared\Map\BrandPin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly PropertyRepository $propertyRepository,
        private readonly PropertyUrlExtension $propertyUrlExtension,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/nos-biens',
            'en' => '/{_locale}/our-properties',
        ],
        name: 'app_property',
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
        if (null === $property) {
            throw $this->createNotFoundException();
        }

        // Redirect 301 if SEO segments don't match (canonical URL)
        $canonicalPath = $this->propertyUrlExtension->propertyShowPath($property, $_locale);
        $currentPath = $this->container->get('request_stack')->getCurrentRequest()->getPathInfo();
        if ($currentPath !== $canonicalPath) {
            return $this->redirect($canonicalPath, 301);
        }

        $map = null;
        $lat = $property->location['lat'] ?? null;
        $lng = $property->location['lng'] ?? null;
        if (null !== $lat && null !== $lng) {
            $point = new Point((float) $lat, (float) $lng);
            $map = (new Map('default'))
                ->center($point)
                ->zoom(15)
                ->addMarker(new Marker(position: $point, icon: BrandPin::icon()))
                ->options(new GoogleOptions(
                    gestureHandling: GestureHandling::GREEDY,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false,
                ));
        }

        $response = $this->render('public/marketplace/show.html.twig', [
            'property' => $property,
            'locale' => $_locale,
            'map' => $map,
        ]);

        // Rented listings are gone for good: 410 tells crawlers to drop them fast
        if ('rented' === $property->status) {
            $response->setStatusCode(Response::HTTP_GONE);
        }

        return $response;
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
        if (null === $property) {
            return new Response('', 404);
        }

        $response = $this->render('components/Marketplace/PropertyCard.html.twig', [
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
