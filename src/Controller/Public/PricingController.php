<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PricingController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/tarifs',
            'en' => '/{_locale}/pricing',
        ],
        name: 'app_pricing',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2026-03-23')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/pricing/index.html.twig');
    }
}
