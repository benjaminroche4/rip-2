<?php

namespace App\Controller\Public;

use App\Service\SanityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function blogList(string $_locale): Response
    {
        $posts = $this->sanityService->query(
            '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] | order(_createdAt desc) {
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
            ['locale' => $_locale]
        );

        return $this->render('public/blog/list.html.twig', [
            'posts' => $posts,
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
