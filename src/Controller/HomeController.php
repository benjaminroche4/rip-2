<?php

namespace App\Controller;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}',
            'en' => '/{_locale}',
        ],
        name: 'app_home',
        options: [
            'sitemap' =>
                [
                    'priority' => 1,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/home/index.html.twig');
    }
}
