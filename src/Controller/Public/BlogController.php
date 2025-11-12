<?php

namespace App\Controller\Public;

use App\Repository\BlogRepository;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly BlogRepository $blogRepository,
    )
    {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/blog',
            'en' => '/{_locale}/blog',
        ],
        name: 'app_blog',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_DAILY,
                ]
        ]
    )]
    public function blogList(): Response
    {
        $posts = $this->blogRepository->findAllVisible();

        return $this->render('public/blog/index.html.twig', [
            'posts' => $posts,
        ]);
    }
}
