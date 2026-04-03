<?php

namespace App\Controller;

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
        name: 'app_properties',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.9,
                    'changefreq' => UrlConcrete::CHANGEFREQ_DAILY,
                    'lastmod' => new \DateTime('2026-04-02')
                ]
        ]
    )]
    public function list(): Response
    {
        return $this->render('public/marketplace/list.html.twig', [
            'controller_name' => 'MarketplaceController',
        ]);
    }
}
