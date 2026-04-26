<?php

namespace App\Blog\Controller;

use App\Blog\Repository\BlogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly BlogRepository $blogRepository,
    ) {}

    #[Route(
        path: [
            'fr' => '/{_locale}/blog',
            'en' => '/{_locale}/blog',
        ],
        name: 'app_blog',
    )]
    public function blogList(Request $request, string $_locale): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $activeCategory = $request->query->get('category') ?: null;

        $hero = $this->blogRepository->findHero($_locale);
        $categories = $this->blogRepository->findCategoriesWithCount($_locale);
        $totalArticles = $this->blogRepository->countAll($_locale);

        $heroSlug = $hero['slug'] ?? '';
        $paginated = $this->blogRepository->findPaginated($_locale, $page, self::PER_PAGE, $activeCategory, $heroSlug);

        $totalCount = $paginated['total'];
        $totalPages = max(1, (int) ceil($totalCount / self::PER_PAGE));

        // Si la page demandée dépasse les pages disponibles, recharge la dernière page
        if ($page > $totalPages) {
            $page = $totalPages;
            $paginated = $this->blogRepository->findPaginated($_locale, $page, self::PER_PAGE, $activeCategory, $heroSlug);
        }

        return $this->render('public/blog/list.html.twig', [
            'posts' => $paginated['posts'],
            'hero' => $hero,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'totalArticles' => $totalArticles,
            'categories' => $categories,
            'activeCategory' => $activeCategory,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/blog/{slug}',
            'en' => '/{_locale}/blog/{slug}',
        ],
        name: 'app_blog_show',
    )]
    public function blogPost(string $slug, string $_locale): Response
    {
        $post = $this->blogRepository->findOneBySlug($slug, $_locale);
        if ($post === null) {
            throw $this->createNotFoundException('The blog post does not exist');
        }

        return $this->render('public/blog/show.html.twig', [
            'post' => $post,
            'latestPosts' => $this->blogRepository->findLatest($_locale, $slug, 3),
        ]);
    }
}
