<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly SanityService $sanityService,
    )
    {
    }

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
    public function index(string $_locale): Response
    {
        $posts = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] | order(_createdAt desc)[0...3] {
                title,
                "slug": slug.current,
                "mainPhoto": mainPhoto.asset->url,
                "mainPhotoAlt": mainPhoto.alt,
                readTime,
                _createdAt,
                "category": category->{name, "color": color.hex},
                "authors": authors[]->{fullName, "photo": photo.asset->url}
            }',
            ['locale' => $_locale]
        );

        return $this->render('public/home/index.html.twig', [
            'posts' => $posts,
        ]);
    }
}
