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
                    'section' => 'blog',
                ]
        ]
    )]
    public function blogList(): Response
    {
        $posts = $this->blogRepository->findAllVisible();

        return $this->render('public/blog/list.html.twig', [
            'posts' => $posts,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/blog/{slugFr}',
            'en' => '/{_locale}/blog/{slugEn}',
        ],
        name: 'app_blog_show',
    )]
    public function blogPost(string $slugFr = null, string $slugEn = null, string $_locale): Response
    {
        $slug = $_locale === 'fr' ? $slugFr : $slugEn;
        $criteria = $_locale === 'fr' ? ['slugFr' => $slug] : ['slugEn' => $slug];

        $post = $this->blogRepository->findOneBy($criteria);

        $lastedBlog = $this->blogRepository->findLatestVisible();

        if (!$post || !$post->isVisible()) {
            throw $this->createNotFoundException('The blog post does not exist');
        }

        return $this->render('public/blog/show.html.twig', [
            'post' => $post,
            'lastedBlog' => $lastedBlog,
        ]);
    }
}
