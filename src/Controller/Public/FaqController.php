<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/faq',
            'en' => '/{_locale}/faq',
        ],
        name: 'app_faq',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.6,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-12-19')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/faq/index.html.twig');
    }
}
