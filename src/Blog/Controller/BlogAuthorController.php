<?php

declare(strict_types=1);

namespace App\Blog\Controller;

use App\Blog\Repository\BlogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Author profile page: who wrote the articles, and the list of their posts
 * (E-E-A-T signal + internal linking hub for the blog).
 */
final class BlogAuthorController extends AbstractController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly BlogRepository $blogRepository,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/blog/auteur/{slug}',
            'en' => '/{_locale}/blog/author/{slug}',
        ],
        name: 'app_blog_author',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $slug, string $_locale): Response
    {
        $author = $this->blogRepository->findAuthorBySlug($slug, $_locale);
        // No published post in this locale = thin page: keep it out of the index
        if (null === $author || 0 === $author->postCount) {
            throw $this->createNotFoundException('The blog author does not exist');
        }

        $categories = $this->blogRepository->findCategoriesWithCountByAuthor($_locale, $slug);

        // Whitelist: only a category this author actually wrote in can filter the list
        $requestedCategory = $request->query->getString('category');
        $activeCategory = in_array($requestedCategory, array_column($categories, 'slug'), true) ? $requestedCategory : null;

        $page = max(1, $request->query->getInt('page', 1));
        $paginated = $this->blogRepository->findPaginatedByAuthor($_locale, $slug, $page, self::PER_PAGE, $activeCategory);
        $totalPages = max(1, (int) ceil($paginated['total'] / self::PER_PAGE));

        // Out-of-range page: fall back to the last one (same behaviour as the blog list)
        if ($page > $totalPages) {
            $page = $totalPages;
            $paginated = $this->blogRepository->findPaginatedByAuthor($_locale, $slug, $page, self::PER_PAGE, $activeCategory);
        }

        return $this->render('public/blog/author.html.twig', [
            'author' => $author,
            'posts' => $paginated['posts'],
            'categories' => $categories,
            'activeCategory' => $activeCategory,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
