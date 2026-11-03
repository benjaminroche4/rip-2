<?php

declare(strict_types=1);

namespace App\Tests\Blog\Controller;

use App\Blog\Repository\BlogRepository;
use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Blog list intro and meta description advertise "More than N guides",
 * N = total posts of the locale rounded down to the ten.
 */
final class BlogListGuidesCountTest extends WebTestCase
{
    private function requestBlogWithTotal(int $total, string $uri = '/fr/blog'): KernelBrowser
    {
        $client = static::createClient();

        // countAll() is cached under a fixed key: start from a clean blog cache
        static::getContainer()->get(TagAwareCacheInterface::class)->invalidateTags([BlogRepository::CACHE_TAG]);

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            static function (string $query, array $params = []) use ($total): mixed {
                if (str_starts_with($query, 'count(')) {
                    // Locale total (countAll) and paginated list count alike
                    return $total;
                }
                // Hero query ([0] slice) has no post
                return str_contains($query, '[0] ') ? null : [];
            }
        );
        static::getContainer()->set(SanityService::class, $sanity);

        $client->request('GET', $uri);

        return $client;
    }

    public function testItAdvertisesTheRoundedDownGuidesCount(): void
    {
        $client = $this->requestBlogWithTotal(163);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le blog pour vivre et s’installer à Paris');
        self::assertSelectorTextContains('main', 'Plus de 160 guides et conseils');
        $description = (string) $client->getCrawler()->filter('meta[name="description"]')->attr('content');
        self::assertStringStartsWith('Plus de 160 guides pour vivre, travailler et s’installer à Paris', $description);
    }

    public function testItKeepsTheGenericCopyBelowTenPosts(): void
    {
        $client = $this->requestBlogWithTotal(7);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Découvrez nos guides et conseils');
        self::assertSelectorTextNotContains('main', 'Plus de');
        $description = (string) $client->getCrawler()->filter('meta[name="description"]')->attr('content');
        self::assertStringStartsWith('Le blog essentiel', $description);
    }

    public function testPaginatedBlogPagesKeepHreflangConsistentWithCanonical(): void
    {
        $client = $this->requestBlogWithTotal(163, '/fr/blog?page=2');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        $canonical = (string) $crawler->filter('link[rel="canonical"]')->attr('href');
        self::assertStringEndsWith('/fr/blog?page=2', $canonical);
        self::assertSame($canonical, $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href'));
        self::assertStringEndsWith('/en/blog?page=2', (string) $crawler->filter('link[rel="alternate"][hreflang="en"]')->attr('href'));
    }
}
