<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EstimationController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/estimation',
            'en' => '/{_locale}/estimation',
        ],
        name: 'app_estimation',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-11-04')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/estimation/index.html.twig');
    }
}
