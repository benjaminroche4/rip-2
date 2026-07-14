<?php

namespace App\Tests\Controller;

use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rendering rules for the Sanity `tableBlock` in blog articles:
 * header toggles (absent = true), caption, empty cells kept, TOC anchor.
 */
final class BlogTableBlockTest extends WebTestCase
{
    /**
     * Each test uses its own slug: BlogRepository caches posts by slug,
     * so a shared slug would leak the first test's payload into the others.
     */
    private string $slug;

    private function createClientWithPost(array $tableBlock, string $slug): KernelBrowser
    {
        $client = static::createClient();

        // BlogRepository caches by slug in a filesystem pool that survives
        // between runs: a unique slug guarantees a cache miss every time
        $slug .= '-'.uniqid();
        $this->slug = $slug;

        $row = [
            'title' => 'Test article',
            'shortDescription' => 'Short description',
            'slug' => $slug,
            'body' => [$tableBlock],
            '_createdAt' => '2026-01-01T00:00:00Z',
        ];

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            // Only the post-detail query projects the body; list queries get an empty set
            static fn (string $query, array $params = []): mixed => str_contains($query, 'body[]') ? $row : []
        );
        static::getContainer()->set(SanityService::class, $sanity);

        return $client;
    }

    private static function baseTableBlock(): array
    {
        return [
            '_type' => 'tableBlock',
            '_key' => 'a1b2c3d4',
            'title' => 'Loyers moyens par quartier',
            'table' => [
                '_type' => 'table',
                'rows' => [
                    ['_type' => 'tableRow', '_key' => 'r1', 'cells' => ['Quartier', 'Loyer moyen', 'Métro']],
                    ['_type' => 'tableRow', '_key' => 'r2', 'cells' => ['Le Marais', '1 800 €', 'Ligne 1']],
                    ['_type' => 'tableRow', '_key' => 'r3', 'cells' => ['Montmartre', '', 'Ligne 12']],
                ],
            ],
            'firstRowIsHeader' => true,
            'firstColumnIsHeader' => true,
            'caption' => 'Prix constatés en janvier 2026',
        ];
    }

    public function testItRendersTableWithHeaderRowAndHeaderColumn(): void
    {
        $client = $this->createClientWithPost(self::baseTableBlock(), 'table-full');
        $crawler = $client->request('GET', '/fr/blog/'.$this->slug);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('div.overflow-x-auto > table')->count());
        $region = $crawler->filter('div.overflow-x-auto');
        self::assertSame('0', $region->attr('tabindex'));
        self::assertSame('region', $region->attr('role'));
        self::assertSame('loyers-moyens-par-quartier-heading', $region->attr('aria-labelledby'));
        self::assertSame(1, $crawler->filter('table thead')->count());
        self::assertSame(3, $crawler->filter('table thead th[scope="col"]')->count());
        self::assertSame('Quartier', trim($crawler->filter('table thead th')->first()->text()));
        self::assertSame(2, $crawler->filter('table tbody tr')->count());
        self::assertSame(2, $crawler->filter('table tbody th[scope="row"]')->count());
        self::assertSame('Le Marais', trim($crawler->filter('table tbody th[scope="row"]')->first()->text()));
        self::assertSame('Prix constatés en janvier 2026', trim($crawler->filter('table caption')->text()));
        self::assertSame('Loyers moyens par quartier', trim($crawler->filter('#loyers-moyens-par-quartier h2')->text()));
    }

    public function testItKeepsEmptyCellsSoColumnsStayAligned(): void
    {
        $client = $this->createClientWithPost(self::baseTableBlock(), 'table-empty-cell');
        $crawler = $client->request('GET', '/fr/blog/'.$this->slug);

        self::assertResponseIsSuccessful();
        $lastRowCells = $crawler->filter('table tbody tr')->last()->filter('th, td');
        self::assertSame(3, $lastRowCells->count());
        self::assertSame('', trim($lastRowCells->eq(1)->text()));
    }

    public function testItRendersPlainCellsWhenTogglesAreFalse(): void
    {
        $block = self::baseTableBlock();
        $block['firstRowIsHeader'] = false;
        $block['firstColumnIsHeader'] = false;
        unset($block['caption'], $block['title']);

        $client = $this->createClientWithPost($block, 'table-no-headers');
        $crawler = $client->request('GET', '/fr/blog/'.$this->slug);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('table thead')->count());
        self::assertSame(0, $crawler->filter('table th')->count());
        self::assertSame(3, $crawler->filter('table tbody tr')->count());
        self::assertSame(0, $crawler->filter('table caption')->count());
        self::assertNotEmpty($crawler->filter('div.overflow-x-auto')->attr('aria-label'));
    }

    public function testItTreatsAbsentTogglesAsTrue(): void
    {
        $block = self::baseTableBlock();
        unset($block['firstRowIsHeader'], $block['firstColumnIsHeader']);

        $client = $this->createClientWithPost($block, 'table-default-toggles');
        $crawler = $client->request('GET', '/fr/blog/'.$this->slug);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('table thead')->count());
        self::assertSame(2, $crawler->filter('table tbody th[scope="row"]')->count());
    }
}
