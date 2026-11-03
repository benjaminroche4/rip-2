<?php

namespace App\Blog\Repository;

use App\Blog\Domain\Author;
use App\Blog\Domain\Post;
use App\Blog\Domain\PostMapper;
use App\Shared\Sanity\SanityService;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Centralizes all Sanity queries for the blog (posts, categories, latest).
 * All reads go through Symfony's cache, invalidated by the Sanity webhook.
 */
final class BlogRepository
{
    public const CACHE_TAG = 'blog';

    private const TTL_POSTS = 86400;
    private const TTL_CATEGORIES = 604800;
    private const TTL_COUNT = 86400;

    private const LIST_FIELDS = '{
        title,
        shortDescription,
        "slug": slug.current,
        "mainPhoto": mainPhoto.asset->url,
        "mainPhotoAlt": mainPhoto.alt,
        readTime,
        _createdAt,
        publishedAt,
        "category": category->{name, "slug": slug.current, "color": color.hex},
        "authors": authors[]->{fullName, "slug": slug.current, "photo": photo.asset->url, "role": role[$locale]}
    }';

    public function __construct(
        private readonly SanityService $sanityService,
        private readonly TagAwareCacheInterface $cache,
        private readonly PostMapper $mapper,
    ) {
    }

    public function findHero(string $locale): ?Post
    {
        $row = $this->cache->get(
            'blog_hero_'.$locale,
            function (ItemInterface $item) use ($locale): ?array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))] | order(_createdAt desc) [0] '.self::LIST_FIELDS,
                    ['locale' => $locale]
                );

                return is_array($result) ? $result : null;
            }
        );

        return null !== $row ? $this->mapper->fromGroqArray($row) : null;
    }

    /**
     * Categories listing is presentational, kept as raw arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findCategoriesWithCount(string $locale): array
    {
        return $this->cache->get(
            'blog_categories_'.$locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::TTL_CATEGORIES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "category"] {
                        name,
                        "slug": slug.current,
                        "color": color.hex,
                        "count": count(*[_type == "blog" && language == $locale && references(^._id) && !(_id in path("drafts.**"))])
                    } [count > 0]',
                    ['locale' => $locale]
                );

                return is_array($results) ? self::sortByName($results, $locale) : [];
            }
        );
    }

    public function countAll(string $locale): int
    {
        return $this->cache->get(
            'blog_total_'.$locale,
            function (ItemInterface $item) use ($locale): int {
                $item->expiresAfter(self::TTL_COUNT);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    'count(*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))])',
                    ['locale' => $locale]
                );

                return is_int($result) ? $result : 0;
            }
        );
    }

    /**
     * Returns the paginated post list (excluding hero, optionally filtered by category) and the matching total count.
     *
     * @return array{posts: array<int, Post>, total: int}
     */
    public function findPaginated(string $locale, int $page, int $perPage, ?string $category, string $heroSlug): array
    {
        $cacheKey = sprintf('blog_list_%s_%s_%d_%d_%s', $locale, $category ?? 'all', $page, $perPage, md5($heroSlug));

        $cached = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $page, $perPage, $category, $heroSlug): array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $categoryFilter = $category ? ' && category->slug.current == $category' : '';
                $baseFilter = '*[_type == "blog" && language == $locale && !(_id in path("drafts.**"))'.$categoryFilter.' && slug.current != $heroSlug]';

                $params = ['locale' => $locale, 'heroSlug' => $heroSlug];
                if ($category) {
                    $params['category'] = $category;
                }

                $total = $this->sanityService->query('count('.$baseFilter.')', $params);
                $total = is_int($total) ? $total : 0;

                $offset = ($page - 1) * $perPage;
                $end = $offset + $perPage;

                $posts = $this->sanityService->query(
                    $baseFilter.' | order(_createdAt desc) [$offset...$end] '.self::LIST_FIELDS,
                    array_merge($params, ['offset' => $offset, 'end' => $end])
                );

                return [
                    'posts' => is_array($posts) ? $posts : [],
                    'total' => $total,
                ];
            }
        );

        return [
            'posts' => $this->mapper->fromGroqArrayList($cached['posts']),
            'total' => $cached['total'],
        ];
    }

    /**
     * Author profile with the number of published posts in this locale.
     */
    public function findAuthorBySlug(string $slug, string $locale): ?Author
    {
        $row = $this->cache->get(
            'blog_author_'.$locale.'_'.md5($slug),
            function (ItemInterface $item) use ($slug, $locale): ?array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    '*[_type == "author" && slug.current == $slug && !(_id in path("drafts.**"))][0] {
                        fullName,
                        "slug": slug.current,
                        "photo": photo.asset->url,
                        "role": role[$locale],
                        "description": description[$locale],
                        "postCount": count(*[_type == "blog" && language == $locale && references(^._id) && !(_id in path("drafts.**"))])
                    }',
                    ['locale' => $locale, 'slug' => $slug]
                );

                return is_array($result) ? $result : null;
            }
        );

        return null !== $row ? Author::fromGroqArray($row) : null;
    }

    /**
     * Authors with at least one published post in this locale (sitemaps), by name.
     *
     * @return list<Author>
     */
    public function findAuthorsWithPosts(string $locale): array
    {
        $rows = $this->cache->get(
            'blog_authors_'.$locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "author" && defined(slug.current) && !(_id in path("drafts.**"))] {
                        fullName,
                        "slug": slug.current,
                        "photo": photo.asset->url,
                        "role": role[$locale],
                        "description": description[$locale],
                        "postCount": count(*[_type == "blog" && language == $locale && references(^._id) && !(_id in path("drafts.**"))]),
                        "lastPostAt": *[_type == "blog" && language == $locale && references(^._id) && !(_id in path("drafts.**"))] | order(_createdAt desc)[0]._createdAt
                    } [postCount > 0] | order(fullName asc)',
                    ['locale' => $locale]
                );

                return is_array($results) ? $results : [];
            }
        );

        return array_values(array_filter(array_map(Author::fromGroqArray(...), $rows)));
    }

    /**
     * Categories the author has written in (this locale), with their post count for that author.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findCategoriesWithCountByAuthor(string $locale, string $authorSlug): array
    {
        return $this->cache->get(
            'blog_author_categories_'.$locale.'_'.md5($authorSlug),
            function (ItemInterface $item) use ($locale, $authorSlug): array {
                $item->expiresAfter(self::TTL_CATEGORIES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "category"] {
                        name,
                        "slug": slug.current,
                        "color": color.hex,
                        "count": count(*[_type == "blog" && language == $locale && references(^._id) && $authorSlug in authors[]->slug.current && !(_id in path("drafts.**"))])
                    } [count > 0]',
                    ['locale' => $locale, 'authorSlug' => $authorSlug]
                );

                return is_array($results) ? self::sortByName($results, $locale) : [];
            }
        );
    }

    /**
     * Paginated posts written by one author (matched on the author slug), optionally filtered by category.
     *
     * @return array{posts: array<int, Post>, total: int}
     */
    public function findPaginatedByAuthor(string $locale, string $authorSlug, int $page, int $perPage, ?string $category = null): array
    {
        $cached = $this->cache->get(
            sprintf('blog_author_list_%s_%s_%s_%d_%d', $locale, md5($authorSlug), $category ?? 'all', $page, $perPage),
            function (ItemInterface $item) use ($locale, $authorSlug, $page, $perPage, $category): array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $categoryFilter = null !== $category ? ' && category->slug.current == $category' : '';
                $baseFilter = '*[_type == "blog" && language == $locale && !(_id in path("drafts.**")) && $authorSlug in authors[]->slug.current'.$categoryFilter.']';
                $params = ['locale' => $locale, 'authorSlug' => $authorSlug];
                if (null !== $category) {
                    $params['category'] = $category;
                }

                $total = $this->sanityService->query('count('.$baseFilter.')', $params);

                $offset = ($page - 1) * $perPage;
                $posts = $this->sanityService->query(
                    $baseFilter.' | order(_createdAt desc) [$offset...$end] '.self::LIST_FIELDS,
                    array_merge($params, ['offset' => $offset, 'end' => $offset + $perPage])
                );

                return [
                    'posts' => is_array($posts) ? $posts : [],
                    'total' => is_int($total) ? $total : 0,
                ];
            }
        );

        return [
            'posts' => $this->mapper->fromGroqArrayList($cached['posts']),
            'total' => $cached['total'],
        ];
    }

    public function findOneBySlug(string $slug, string $locale): ?Post
    {
        $row = $this->cache->get(
            'blog_post_'.$locale.'_'.md5($slug),
            function (ItemInterface $item) use ($slug, $locale): ?array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
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
                        _updatedAt,
                        publishedAt,
                        "category": category->{name, "color": color.hex},
                        "authors": authors[]->{fullName, "slug": slug.current, "photo": photo.asset->url, "role": role[$locale]},
                        "tags": tags[],
                        "alternateSlug": *[_type == "translation.metadata" && references(^._id)]{
                            translations[_key != $locale]{
                                _key,
                                "slug": value->slug.current
                            }
                        }[0].translations[0]
                    }',
                    ['locale' => $locale, 'slug' => $slug]
                );

                return is_array($result) ? $result : null;
            }
        );

        return null !== $row ? $this->mapper->fromGroqArray($row) : null;
    }

    /**
     * @return array<int, Post>
     */
    public function findLatest(string $locale, string $excludeSlug, int $limit = 3): array
    {
        $rows = $this->cache->get(
            sprintf('blog_latest_%s_%d_%s', $locale, $limit, md5($excludeSlug)),
            function (ItemInterface $item) use ($locale, $excludeSlug, $limit): array {
                $item->expiresAfter(self::TTL_POSTS);
                $item->tag(self::CACHE_TAG);

                $end = $limit;
                $results = $this->sanityService->query(
                    '*[_type == "blog" && language == $locale && slug.current != $slug && !(_id in path("drafts.**"))] | order(_createdAt desc)[0...$end] {
                        title,
                        shortDescription,
                        "slug": slug.current,
                        "mainPhoto": mainPhoto.asset->url,
                        "mainPhotoAlt": mainPhoto.alt,
                        readTime,
                        _createdAt,
                        _updatedAt,
                        publishedAt,
                        "category": category->{name, "color": color.hex},
                        "authors": authors[]->{fullName, "slug": slug.current, "photo": photo.asset->url, "role": role[$locale]}
                    }',
                    ['locale' => $locale, 'slug' => $excludeSlug, 'end' => $end]
                );

                return is_array($results) ? $results : [];
            }
        );

        return $this->mapper->fromGroqArrayList($rows);
    }

    /**
     * Locale-aware alphabetical sort: GROQ orders by code point, which pushes
     * accented initials ("Étudiants") after "Z".
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sortByName(array $rows, string $locale): array
    {
        $collator = new \Collator($locale);
        usort($rows, static fn (array $a, array $b): int => (int) $collator->compare((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $rows;
    }
}
