<?php

declare(strict_types=1);

namespace App\Tests\Blog\Controller;

use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class BlogAuthorControllerTest extends WebTestCase
{
    /**
     * BlogRepository caches by slug in a persistent pool: every test uses
     * unique slugs so it never reads another test's (or run's) payload.
     */
    private string $authorSlug;

    /**
     * @param array<string, mixed>|null $authorRow
     * @param list<array<string, mixed>> $posts
     * @param array<string, mixed>|null $postRow
     * @param list<array<string, mixed>> $categories
     */
    private function createClientWithSanity(?array $authorRow, array $posts = [], ?array $postRow = null, array $categories = []): KernelBrowser
    {
        $client = static::createClient();

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            static function (string $query, array $params = []) use ($authorRow, $posts, $postRow, $categories): mixed {
                if (str_contains($query, 'body[]')) {
                    return $postRow;
                }
                if (str_contains($query, '_type == "author"')) {
                    return $authorRow;
                }
                if (isset($params['authorSlug']) && str_contains($query, '_type == "category"')) {
                    return $categories;
                }
                if (isset($params['authorSlug'])) {
                    // Mimics the GROQ category filter, only applied when the controller passes one
                    $matching = isset($params['category'])
                        ? array_values(array_filter($posts, static fn (array $p): bool => ($p['category']['slug'] ?? null) === $params['category']))
                        : $posts;

                    return str_starts_with($query, 'count(') ? count($matching) : $matching;
                }

                return [];
            }
        );
        static::getContainer()->set(SanityService::class, $sanity);

        return $client;
    }

    /** @return array<string, mixed> */
    private function authorRow(int $postCount): array
    {
        $this->authorSlug = 'jane-doe-'.uniqid();

        return [
            'fullName' => 'Jane Doe',
            'slug' => $this->authorSlug,
            'photo' => 'https://cdn.sanity.io/images/x/production/jane.webp',
            'postCount' => $postCount,
        ];
    }

    /** @return array<string, mixed> */
    private function postRow(string $slug, string $title, ?string $categorySlug = null): array
    {
        return [
            'category' => null !== $categorySlug ? ['name' => ucfirst($categorySlug), 'slug' => $categorySlug, 'color' => '#ff0000'] : null,
            'title' => $title,
            'shortDescription' => 'Short description',
            'slug' => $slug,
            '_createdAt' => '2026-01-01T00:00:00Z',
            'authors' => [['fullName' => 'Jane Doe', 'slug' => $this->authorSlug, 'role' => 'Chasseuse immobilière']],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function jsonLdNodes(Crawler $crawler): array
    {
        $nodes = [];
        $crawler->filter('script[type="application/ld+json"]')->each(
            static function (Crawler $script) use (&$nodes): void {
                $data = json_decode($script->text(), true, flags: \JSON_THROW_ON_ERROR);
                foreach ($data['@graph'] ?? [$data] as $node) {
                    $nodes[] = $node;
                }
            }
        );

        return $nodes;
    }

    public function testItListsTheAuthorPostsWithProfileSchema(): void
    {
        $author = $this->authorRow(2);
        $client = $this->createClientWithSanity($author, [
            $this->postRow('first-post', 'First post'),
            $this->postRow('second-post', 'Second post'),
        ]);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jane Doe');
        self::assertSelectorExists('a[href="/fr/blog/first-post"]');
        self::assertSelectorExists('a[href="/fr/blog/second-post"]');
        self::assertStringEndsWith('/fr/blog/auteur/'.$this->authorSlug, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringEndsWith('/en/blog/author/'.$this->authorSlug, (string) $crawler->filter('link[hreflang="en"]')->attr('href'));

        $nodes = self::jsonLdNodes($crawler);
        $types = array_column($nodes, '@type');
        self::assertContains('ProfilePage', $types);
        self::assertContains('Person', $types);
        self::assertContains('ItemList', $types);

        $person = array_values(array_filter($nodes, static fn (array $n): bool => 'Person' === ($n['@type'] ?? null)))[0];
        self::assertSame(['fr', 'en'], array_column($person['knowsLanguage'], 'alternateName'));
        self::assertStringEndsWith('/#organization', $person['worksFor']['@id']);
        // The company LinkedIn is not the person's identity
        self::assertArrayNotHasKey('sameAs', $person);
    }

    public function testItShowsBreadcrumbLanguagesAndCompanyLinkedIn(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(1), [$this->postRow('a-post', 'A post')]);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        $crumbs = $crawler->filter('nav[aria-label="Breadcrumb"] li')->each(static fn ($li): string => trim($li->text()));
        self::assertSame(['Accueil', 'Blog', 'Auteur', 'Jane Doe'], $crumbs);
        self::assertSelectorTextContains('header[aria-labelledby="author-name"]', 'Langues parlées');
        $languages = $crawler->filter('header[aria-labelledby="author-name"] ul li')->each(static fn ($li): string => trim($li->text()));
        self::assertSame(['Français', 'Anglais'], $languages);

        $linkedin = $crawler->filter('header[aria-labelledby="author-name"] a[href="https://www.linkedin.com/company/relocation-in-paris/"]');
        self::assertCount(1, $linkedin);
        self::assertSame('noopener noreferrer', $linkedin->attr('rel'));
        self::assertSame('_blank', $linkedin->attr('target'));
    }

    public function testItUsesTheLocalizedAuthorPathInEnglish(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(1), [$this->postRow('english-post', 'English post')]);

        $client->request('GET', '/en/blog/author/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2#author-posts-heading', 'Articles by Jane Doe');
        self::assertSelectorTextContains('a[aria-current="page"]', 'All articles (1)');
    }

    public function testItPaginatesTheAuthorPosts(): void
    {
        $author = $this->authorRow(10);
        $posts = array_map(fn (int $i): array => $this->postRow('post-'.$i, 'Post '.$i), range(1, 10));
        $client = $this->createClientWithSanity($author, $posts);

        $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('nav[aria-label="Pagination"] a[href="/fr/blog/auteur/%s?page=2"]', $this->authorSlug));
        self::assertSelectorTextSame('title', 'Relocation In Paris - Jane Doe : articles sur Paris');
    }

    public function testItGivesPaginatedPagesADistinctTitleAndCanonical(): void
    {
        $author = $this->authorRow(10);
        $posts = array_map(fn (int $i): array => $this->postRow('post-'.$i, 'Post '.$i), range(1, 10));
        $client = $this->createClientWithSanity($author, $posts);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug.'?page=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Relocation In Paris - Jane Doe : articles sur Paris (page 2)');
        self::assertStringEndsWith('(page 2)', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringEndsWith('/fr/blog/auteur/'.$this->authorSlug.'?page=2', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCanonicalMatchesSelfHreflang($crawler, 'fr');
        self::assertStringEndsWith('/en/blog/author/'.$this->authorSlug.'?page=2', (string) $crawler->filter('link[hreflang="en"]')->attr('href'));
    }

    public function testFilteredViewsKeepHreflangOnTheUnfilteredCanonical(): void
    {
        $author = $this->authorRow(10);
        $posts = array_map(fn (int $i): array => $this->postRow('post-'.$i, 'Post '.$i, 'logement'), range(1, 10));
        $client = $this->createClientWithSanity($author, $posts, null, [['name' => 'Logement', 'slug' => 'logement', 'count' => 10]]);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug.'?category=logement&page=2');

        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('/fr/blog/auteur/'.$this->authorSlug, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCanonicalMatchesSelfHreflang($crawler, 'fr');
    }

    /** Google ignores hreflang when the page's own alternate contradicts its canonical. */
    private static function assertCanonicalMatchesSelfHreflang(Crawler $crawler, string $locale): void
    {
        self::assertSame(
            $crawler->filter('link[rel="canonical"]')->attr('href'),
            $crawler->filter(sprintf('link[rel="alternate"][hreflang="%s"]', $locale))->attr('href'),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function categories(): array
    {
        return [
            ['name' => 'Logement', 'slug' => 'logement', 'color' => '#ff0000', 'count' => 1],
            ['name' => 'Quartiers', 'slug' => 'quartiers', 'color' => '#00ff00', 'count' => 1],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categorizedPosts(): array
    {
        return [
            $this->postRow('housing-post', 'Housing post', 'logement'),
            $this->postRow('district-post', 'District post', 'quartiers'),
        ];
    }

    public function testItShowsTheAuthorCategoryTabsWithCounts(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(2), $this->categorizedPosts(), null, self::categories());

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[aria-current="page"]', 'Tous les articles (2)');
        $housingTab = $crawler->filter(sprintf('a[href="/fr/blog/auteur/%s?category=logement"]', $this->authorSlug));
        self::assertCount(1, $housingTab);
        self::assertStringContainsString('Logement (1)', $housingTab->text());
    }

    public function testItFiltersTheAuthorPostsByCategory(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(2), $this->categorizedPosts(), null, self::categories());

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug.'?category=logement');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/fr/blog/housing-post"]');
        self::assertSelectorNotExists('a[href="/fr/blog/district-post"]');
        self::assertSelectorTextContains('a[aria-current="page"]', 'Logement (1)');
        // Filtered view canonicalizes to the unfiltered author page
        self::assertStringEndsWith('/fr/blog/auteur/'.$this->authorSlug, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testItIgnoresACategoryTheAuthorNeverWroteIn(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(2), $this->categorizedPosts(), null, self::categories());

        $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug.'?category=not-a-category');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/fr/blog/housing-post"]');
        self::assertSelectorExists('a[href="/fr/blog/district-post"]');
        self::assertSelectorTextContains('a[aria-current="page"]', 'Tous les articles (2)');
    }

    public function testItUsesTheSanityRoleAndBioForContentMetaAndSchema(): void
    {
        $author = $this->authorRow(1) + [
            'role' => 'Chasseuse immobilière',
            'description' => 'Chasseuse immobilière à Paris, j’arpente la ville chaque semaine pour nos clients.',
        ];
        $client = $this->createClientWithSanity($author, [$this->postRow('a-post', 'A post')]);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        $header = 'header[aria-labelledby="author-name"]';
        self::assertSelectorTextContains($header, 'Chasseuse immobilière');
        self::assertSelectorTextContains($header, 'j’arpente la ville chaque semaine');
        self::assertSelectorTextNotContains($header, 'Expert en relocation');
        self::assertSame($author['description'], $crawler->filter('meta[name="description"]')->attr('content'));

        $person = array_values(array_filter(self::jsonLdNodes($crawler), static fn (array $n): bool => 'Person' === ($n['@type'] ?? null)))[0];
        self::assertSame('Chasseuse immobilière', $person['jobTitle']);
        self::assertSame($author['description'], $person['description']);
    }

    public function testItFallsBackToTheGenericRoleAndIntroWhenSanityFieldsAreEmpty(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(1) + ['role' => '  ', 'description' => null], [$this->postRow('a-post', 'A post')]);

        $crawler = $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header[aria-labelledby="author-name"]', 'Expert en relocation');
        self::assertSelectorTextContains('header[aria-labelledby="author-name"]', 'Au sein de l’équipe Relocation In Paris');
        self::assertStringStartsWith('Tous les articles signés Jane Doe', (string) $crawler->filter('meta[name="description"]')->attr('content'));
    }

    public function testItReturns404ForAnUnknownAuthor(): void
    {
        $client = $this->createClientWithSanity(null);

        $client->request('GET', '/fr/blog/auteur/unknown-'.uniqid());

        self::assertResponseStatusCodeSame(404);
    }

    public function testItReturns404WhenTheAuthorHasNoPostInThisLocale(): void
    {
        $client = $this->createClientWithSanity($this->authorRow(0));

        $client->request('GET', '/fr/blog/auteur/'.$this->authorSlug);

        self::assertResponseStatusCodeSame(404);
    }

    public function testArticleLinksToItsAuthorPageAndSchemaPerson(): void
    {
        $this->authorSlug = 'jane-doe-'.uniqid();
        $postSlug = 'article-'.uniqid();
        $client = $this->createClientWithSanity(null, [], $this->postRow($postSlug, 'An article'));

        $crawler = $client->request('GET', '/fr/blog/'.$postSlug);

        self::assertResponseIsSuccessful();
        $authorPath = '/fr/blog/auteur/'.$this->authorSlug;
        self::assertSelectorExists(sprintf('a[rel="author"][href="%s"]', $authorPath));
        self::assertSelectorTextContains(sprintf('a[rel="author"][href="%s"]', $authorPath), 'Chasseuse immobilière');
        // Built from site_url like the canonical, never from the request host
        self::assertCanonicalMatchesSelfHreflang($crawler, 'fr');

        $article = array_values(array_filter(self::jsonLdNodes($crawler), static fn (array $n): bool => 'BlogPosting' === ($n['@type'] ?? null)))[0];
        self::assertStringEndsWith($authorPath.'#person', $article['author'][0]['@id']);
    }
}
