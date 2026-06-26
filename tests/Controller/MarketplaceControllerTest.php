<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketplaceControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/nos-biens');

        self::assertResponseIsSuccessful();
        // New unified search bar: free-text input + the three filter pills.
        self::assertSame(1, $crawler->filter('input[type="search"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-controller~="more-filters-modal"]')->count());
        self::assertGreaterThanOrEqual(2, $crawler->filter('[data-controller~="arrondissement-dropdown"]')->count());
    }

    public function testIndexWithFiltersInQueryStringSucceeds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/nos-biens', [
            'bedrooms' => ['studio'],
            'furnished' => ['yes'],
            'longTerm' => 1,
            'nearMetro' => 1,
            'rentMin' => 2000,
        ]);

        self::assertResponseIsSuccessful();
    }
}
