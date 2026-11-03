<?php

declare(strict_types=1);

namespace App\Tests\Blog\Controller;

use App\Blog\Repository\BlogRepository;
use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Author pages are exposed in both sitemaps: their own XML section
 * (sitemap.blog_author.xml) and the HTML /sitemap page.
 */
final class BlogAuthorSitemapTest extends WebTestCase
{
    private function createClientWithAuthors(): KernelBrowser
    {
        $client = static::createClient();

        // The author list is cached under a fixed key: start from a clean blog cache
        static::getContainer()->get(TagAwareCacheInterface::class)->invalidateTags([BlogRepository::CACHE_TAG]);

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            static fn (string $query, array $params = []): mixed => str_contains($query, 'order(fullName asc)')
                ? [
                    ['fullName' => 'Jane Doe', 'slug' => 'jane-doe', 'postCount' => 3, 'lastPostAt' => '2026-10-01T08:00:00Z'],
                    // Sanity filters authors without posts; the DTO also drops rows without slug
                    ['fullName' => 'No Slug', 'slug' => null, 'postCount' => 1],
                ]
                : []
        );
        static::getContainer()->set(SanityService::class, $sanity);

        return $client;
    }

    public function testItListsAuthorPagesInTheirOwnXmlSitemapSection(): void
    {
        $client = $this->createClientWithAuthors();

        $client->request('GET', '/sitemap.blog_author.xml');

        self::assertResponseIsSuccessful();
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/fr/blog/auteur/jane-doe</loc>', $xml);
        self::assertStringContainsString('/en/blog/author/jane-doe</loc>', $xml);
        self::assertStringContainsString('<lastmod>2026-10-01', $xml);
        self::assertStringNotContainsString('No Slug', $xml);
    }

    public function testItLinksAuthorPagesFromTheHtmlSitemap(): void
    {
        $client = $this->createClientWithAuthors();

        $client->request('GET', '/fr/sitemap');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[href="/fr/blog/auteur/jane-doe"]', 'Jane Doe');
    }
}
