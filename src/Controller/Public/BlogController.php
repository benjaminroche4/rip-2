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
        $perPage = 9;
        $page = max(1, $request->query->getInt('page', 1));
        $activeCategory = $request->query->get('category');

        $fields = '{
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
        }';

        // Hero: always the latest article, independent of pagination/category
        $hero = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] | order(_createdAt desc) [0] ' . $fields,
            ['locale' => $_locale]
        );

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

        // Total articles (including hero, no category filter) — for "All articles" pill
        $totalArticles = $this->sanityService->query(
            'count(*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))])',
            ['locale' => $_locale]
        );

        // Grid: always exclude hero, optionally filter by category
        $categoryFilter = $activeCategory ? ' && category->slug.current == $category' : '';
        $baseFilter = '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))' . $categoryFilter . ' && slug.current != $heroSlug]';

        $params = ['locale' => $_locale, 'heroSlug' => $hero ? $hero['slug'] : ''];
        if ($activeCategory) {
            $params['category'] = $activeCategory;
        }

        $totalCount = $this->sanityService->query(
            'count(' . $baseFilter . ')',
            $params
        );

        $offset = ($page - 1) * $perPage;
        $totalPages = max(1, (int) ceil($totalCount / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $end = $offset + $perPage;

        $posts = $this->sanityService->query(
            $baseFilter . ' | order(_createdAt desc) [$offset...$end] ' . $fields,
            array_merge($params, ['offset' => $offset, 'end' => $end])
        );

        return $this->render('public/blog/list.html.twig', [
            'posts' => $posts,
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
