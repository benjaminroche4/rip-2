<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
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
        return $this->render('public/marketplace/list.html.twig', [
            'locale' => $_locale,
        ]);
    }
}
