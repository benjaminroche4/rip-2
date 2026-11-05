<?php

namespace App\Tests\CaseStudy\Controller;

use App\CaseStudy\Domain\CaseStudy;
use App\CaseStudy\Repository\CaseStudyRepositoryInterface;
use App\CaseStudy\Repository\MockCaseStudyRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CaseStudyPaginationTest extends WebTestCase
{
    public function testItPaginatesNineCaseStudiesPerPage(): void
    {
        $client = static::createClient();
        // Keep the fake repository across the two requests
        $client->disableReboot();
        static::getContainer()->set(CaseStudyRepositoryInterface::class, self::repositoryWith(12));

        $crawler = $client->request('GET', '/fr/etudes-de-cas');
        self::assertSelectorCount(9, '[data-testid="case-study-card"]');
        self::assertSelectorExists('a[href="/fr/etudes-de-cas?page=2"]');

        $crawler = $client->request('GET', '/fr/etudes-de-cas?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '[data-testid="case-study-card"]');
        // Page 2 has its own canonical and title, so it is not a duplicate of page 1
        self::assertStringEndsWith('/fr/etudes-de-cas?page=2', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSelectorTextContains('title', '2');
    }

    public function testAPageBeyondTheLastOneShowsTheLastPage(): void
    {
        $client = static::createClient();
        static::getContainer()->set(CaseStudyRepositoryInterface::class, self::repositoryWith(12));

        $client->request('GET', '/fr/etudes-de-cas?page=99');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '[data-testid="case-study-card"]');
    }

    public function testWithOnlyTheFeaturedCaseStudyThereIsNoEmptyGridUnderIt(): void
    {
        $client = static::createClient();
        $base = (new MockCaseStudyRepository())->findAll('fr')[0];
        $only = new CaseStudy(...array_merge(get_object_vars($base), ['featured' => true]));
        static::getContainer()->set(CaseStudyRepositoryInterface::class, new class([$only]) implements CaseStudyRepositoryInterface {
            /** @param list<CaseStudy> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function findAll(string $locale): array
            {
                return $this->items;
            }

            public function findOneBySlug(string $slug, string $locale): ?CaseStudy
            {
                return null;
            }
        });

        $client->request('GET', '/fr/etudes-de-cas');

        self::assertSelectorCount(1, '[data-testid="case-study-featured"]');
        self::assertSelectorNotExists('turbo-frame#case-study-list');
        self::assertSelectorNotExists('[data-testid="case-study-stats"]', 'Figures need at least two case studies');
    }

    public function testTheProcessWrittenInTheCmsReplacesTheStandardSteps(): void
    {
        $client = static::createClient();
        $base = (new MockCaseStudyRepository())->findAll('fr')[0];
        $custom = new CaseStudy(...array_merge(get_object_vars($base), ['process' => [
            ['title' => 'Brief avec les RH', 'text' => 'Un appel de 30 minutes.'],
            ['title' => 'Visites groupées', 'text' => 'Six logements en une journée.'],
        ]]));
        static::getContainer()->set(CaseStudyRepositoryInterface::class, new class([$custom]) implements CaseStudyRepositoryInterface {
            /** @param list<CaseStudy> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function findAll(string $locale): array
            {
                return $this->items;
            }

            public function findOneBySlug(string $slug, string $locale): ?CaseStudy
            {
                return $this->items[0]->slug === $slug ? $this->items[0] : null;
            }
        });

        $crawler = $client->request('GET', '/fr/etudes-de-cas/'.$base->slug);

        self::assertSame(
            ['Brief avec les RH', 'Visites groupées'],
            $crawler->filter('section[aria-labelledby="case-study-process"] h3')->each(static fn ($h): string => trim($h->text())),
        );
    }

    /** The mock data set repeated with unique slugs, to get past one page. */
    private static function repositoryWith(int $count): CaseStudyRepositoryInterface
    {
        $base = (new MockCaseStudyRepository())->findAll('fr');
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $source = $base[$i % \count($base)];
            $items[] = new CaseStudy(...array_merge(get_object_vars($source), [
                'slug' => $source->slug.'-'.$i,
                'publishedAt' => $source->publishedAt->modify('-'.$i.' days'),
                // No featured one: every case study is a grid card here
                'featured' => false,
            ]));
        }

        return new class($items) implements CaseStudyRepositoryInterface {
            /** @param list<CaseStudy> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function findAll(string $locale): array
            {
                return $this->items;
            }

            public function findOneBySlug(string $slug, string $locale): ?CaseStudy
            {
                return null;
            }
        };
    }
}
