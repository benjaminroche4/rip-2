<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Repository\PropertyRepository;
use App\Marketplace\Twig\Extension\PropertyUrlExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

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

        $map = null;
        if (!empty($property['location']['lat']) && !empty($property['location']['lng'])) {
            $point = new Point(
                (float) $property['location']['lat'],
                (float) $property['location']['lng'],
            );
            $pinSvg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="39" height="50" viewBox="0 0 44 56">
    <path d="M22 0C9.85 0 0 9.85 0 22c0 16.5 22 34 22 34s22-17.5 22-34C44 9.85 34.15 0 22 0Z" fill="#71172e"/>
    <circle cx="22" cy="22" r="13" fill="white"/>
    <path d="M22 14.5a2 2 0 0 0-1.28.46l-5.44 4.53A2 2 0 0 0 14.57 21v7.5a1.5 1.5 0 0 0 1.5 1.5h3v-4.5a1.5 1.5 0 0 1 1.5-1.5h2.86a1.5 1.5 0 0 1 1.5 1.5V30h3a1.5 1.5 0 0 0 1.5-1.5V21a2 2 0 0 0-.71-1.51l-5.44-4.53A2 2 0 0 0 22 14.5Z" fill="#71172e" stroke="#71172e" stroke-width="0.5" stroke-linejoin="round"/>
</svg>
SVG;
            $map = (new Map('default'))
                ->center($point)
                ->zoom(15)
                ->addMarker(new Marker(position: $point, icon: Icon::svg($pinSvg)))
                ->options(new GoogleOptions(
                    gestureHandling: GestureHandling::GREEDY,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false,
                ));
        }

        return $this->render('public/marketplace/show.html.twig', [
            'property' => $property,
            'locale' => $_locale,
            'map' => $map,
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
