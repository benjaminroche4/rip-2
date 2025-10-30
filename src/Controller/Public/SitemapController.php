<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/sitemap',
            'en' => '/{_locale}/sitemap',
        ],
        name: 'app_sitemap',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.1,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/sitemap/index.html.twig');
    }
}
