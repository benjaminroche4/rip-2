<?php

namespace App\Controller;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServiceController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/services/trouver-un-logement',
            'en' => '/{_locale}/services/find-an-accommodation',
        ],
        name: 'app_service_find_accommodation',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function findAccommodation(): Response
    {
        return $this->render('public/services/findAccommodation.html.twig');
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/services/pour-les-entreprises',
            'en' => '/{_locale}/services/for-companies',
        ],
        name: 'app_service_companies',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function company(): Response
    {
        return $this->render('public/services/companies.html.twig');
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/services/pour-les-proprietaires',
            'en' => '/{_locale}/services/for-landlords',
        ],
        name: 'app_service_landlords',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function owner(): Response
    {
        return $this->render('public/services/landlords.html.twig');
    }
}
