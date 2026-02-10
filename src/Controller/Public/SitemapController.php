<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly SanityService $sanityService,
    )
    {
    }

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
    public function index(string $_locale): Response
    {
        $posts = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] | order(_createdAt desc) {
                title,
                "slug": slug.current
            }',
            ['locale' => $_locale]
        );

        return $this->render('public/sitemap/index.html.twig', [
            'posts' => $posts,
        ]);
    }
}