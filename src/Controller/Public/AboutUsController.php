<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AboutUsController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/a-propos',
            'en' => '/{_locale}/about-us',
        ],
        name: 'app_about_us',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.6,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-10-30')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/about_us/index.html.twig');
    }
}
