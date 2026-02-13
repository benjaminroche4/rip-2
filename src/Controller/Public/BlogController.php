<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly SanityService $sanityService,
    )
    {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/blog',
            'en' => '/{_locale}/blog',
        ],
        name: 'app_blog',
    )]
    public function blogList(Request $request, string $_locale): Response
    {
        $perPage = 2;
        $page = max(1, $request->query->getInt('page', 1));
        $activeCategory = $request->query->get('category');

        // Categories with at least 1 article
        $categories = $this->sanityService->query(
            '*[_type == "category"] {
                name,
                "slug": slug.current,
                "color": color.hex,
                "count": count(*[_type == "blog" && language == $locale && references(^._id) && !(_id in path("drafts.**"))])
            } [count > 0] | order(name asc)',
            ['locale' => $_locale]
        );

        // Build filter
        $categoryFilter = $activeCategory ? ' && category->slug.current == $category' : '';
        $baseFilter = '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))' . $categoryFilter . ']';

        $params = ['locale' => $_locale];
        if ($activeCategory) {
            $params['category'] = $activeCategory;
        }

        $totalCount = $this->sanityService->query(
            'count(' . $baseFilter . ')',
            $params
        );

        // With category filter: no hero, simple pagination
        // Without filter: page 1 has hero + grid, page 2+ grid only
        $hasHero = !$activeCategory && $page === 1;
        $isFirstPage = $page === 1;

        if ($activeCategory) {
            $offset = ($page - 1) * $perPage;
            $limit = $perPage;
            $totalPages = max(1, (int) ceil($totalCount / $perPage));
        } else {
            $limit = $hasHero ? $perPage + 1 : $perPage;
            $offset = $isFirstPage ? 0 : ($page - 1) * $perPage + 1;
            $totalPages = $totalCount <= 1 ? 1 : (int) ceil(($totalCount - 1) / $perPage);
        }

        if ($page > $totalPages) {
            $page = $totalPages;
            if ($activeCategory) {
                $offset = ($page - 1) * $perPage;
            } else {
                $offset = $isFirstPage ? 0 : ($page - 1) * $perPage + 1;
            }
        }

        $end = $offset + $limit;

        $posts = $this->sanityService->query(
            $baseFilter . ' | order(_createdAt desc) [$offset...$end] {
                title,
                shortDescription,
                "slug": slug.current,
                "mainPhoto": mainPhoto.asset->url,
                "mainPhotoAlt": mainPhoto.alt,
                readTime,
                _createdAt,
                publishedAt,
                "category": category->{name, "slug": slug.current, "color": color.hex},
                "authors": authors[]->{fullName, "photo": photo.asset->url}
            }',
            array_merge($params, ['offset' => $offset, 'end' => $end])
        );

        return $this->render('public/blog/list.html.twig', [
            'posts' => $posts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'isFirstPage' => $isFirstPage,
            'hasHero' => $hasHero,
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
        $post = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && slug.current == $slug && !(_id in path("drafts.**"))][0] {
                title,
                shortDescription,
                metaDescription,
                "slug": slug.current,
                "mainPhoto": mainPhoto.asset->url,
                "mainPhotoAlt": mainPhoto.alt,
                readTime,
                body[]{
                    ...,
                    _type == "wysiwygBlock" => {
                        ...,
                        content[]{
                            ...,
                            _type == "image" => {
                                ...,
                                "url": asset->url
                            }
                        }
                    }
                },
                _createdAt,
                publishedAt,
                "category": category->{name, "color": color.hex},
                "authors": authors[]->{fullName, "photo": photo.asset->url},
                "tags": tags[],
                "alternateSlug": *[_type == "translation.metadata" && references(^._id)]{
                    translations[_key != $locale]{
                        _key,
                        "slug": value->slug.current
                    }
                }[0].translations[0]
            }',
            ['locale' => $_locale, 'slug' => $slug]
        );

        if (!$post) {
            throw $this->createNotFoundException('The blog post does not exist');
        }

        $latestPosts = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && slug.current != $slug && !(_id in path("drafts.**"))] | order(_createdAt desc)[0...3] {
                title,
                shortDescription,
                "slug": slug.current,
                "mainPhoto": mainPhoto.asset->url,
                "mainPhotoAlt": mainPhoto.alt,
                readTime,
                _createdAt,
                publishedAt,
                "category": category->{name, "color": color.hex},
                "authors": authors[]->{fullName, "photo": photo.asset->url}
            }',
            ['locale' => $_locale, 'slug' => $slug]
        );

        return $this->render('public/blog/show.html.twig', [
            'post' => $post,
            'latestPosts' => $latestPosts,
        ]);
    }
}
