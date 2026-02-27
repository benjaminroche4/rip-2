<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/',
            'en' => '/{_locale}',
        ],
        name: 'app_home',
        options: [
            'sitemap' =>
                [
                    'priority' => 1,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2026-01-04')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/home/index.html.twig');
    }
}
