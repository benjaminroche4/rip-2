<?php

namespace App\Tests\Marketplace\Twig;

use App\Marketplace\Twig\Components\MarketplaceSearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit coverage of the search component's filter state machine (no Sanity / map
 * rendering): normalisation, draft application, the curated-area shortcut and
 * the "More filters" badge count.
 */
final class MarketplaceSearchTest extends KernelTestCase
{
    private function component(): MarketplaceSearch
    {
        return self::getContainer()->get(MarketplaceSearch::class);
    }

    public function testMountNormalisesAndWhitelistsFilters(): void
    {
        $c = $this->component();
        $c->mount(
            locale: 'en',
            arrondissements: [21, 3, 3, 7],   // 21 invalid, 3 duplicated
            bedrooms: ['studio', 'penthouse'], // penthouse not whitelisted
            furnished: ['yes', 'maybe'],       // maybe not whitelisted
            rentMin: 500,                       // below floor -> dropped
            features: ['balcony', 'unicorn'],   // unicorn not whitelisted
            availability: 'someday',            // invalid -> null
        );

        self::assertSame([3, 7], $c->arrondissements);
        self::assertSame(['studio'], $c->bedrooms);
        self::assertSame(['yes'], $c->furnished);
        self::assertNull($c->rentMin);
        self::assertSame(['balcony'], $c->features);
        self::assertNull($c->availability);
    }

    public function testSelectAreaSelectsArrondissementsAndPinsTheMap(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');

        $c->selectArea('3,4');

        self::assertSame([3, 4], $c->arrondissements);
        self::assertSame([3, 4], $c->draftArrondissements);
        self::assertNotNull($c->pingLat);
        self::assertNotNull($c->pingLng);
    }

    public function testSearchAddressPinsExactLocationAndFiltersArrondissement(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');

        $c->searchAddress(48.8606, 2.3376, 1);

        self::assertSame([1], $c->arrondissements);
        self::assertSame([1], $c->draftArrondissements);
        self::assertSame(48.8606, $c->pingLat);
        self::assertSame(2.3376, $c->pingLng);
        self::assertTrue($c->pingExplicit);
        self::assertSame(16.0, $c->zoom);
        self::assertSame(1, $c->page);
    }

    public function testSearchAddressWithoutArrondissementKeepsFiltersButPins(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [5]);

        $c->searchAddress(48.85, 2.35, 0);

        // Out-of-Paris postal code (arr 0) leaves the arrondissement filter untouched.
        self::assertSame([5], $c->arrondissements);
        self::assertTrue($c->pingExplicit);
        self::assertSame(48.85, $c->pingLat);
    }

    public function testSearchAppliesDraftsAndResetsPagination(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');
        $c->page = 5;

        $c->draftBedrooms = ['2'];
        $c->draftFurnished = ['no'];
        $c->draftLongTerm = true;
        $c->draftRentMin = 2500;

        $c->search();

        self::assertSame(['2'], $c->bedrooms);
        self::assertSame(['no'], $c->furnished);
        self::assertTrue($c->longTerm);
        self::assertSame(2500, $c->rentMin);
        self::assertSame(1, $c->page);
    }

    public function testMoreFiltersCountReflectsActiveMoreFilters(): void
    {
        $c = $this->component();
        $c->mount(
            locale: 'fr',
            rentMin: 3000,
            features: ['balcony', 'parking'],
            availability: 'now',
            nearMetro: true,
        );

        // rentMin (1) + 2 features + availability (1) + nearMetro (1) = 5
        self::assertSame(5, $c->getMoreFiltersCount());
    }

    public function testClearFiltersResetsEverything(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [3], bedrooms: ['studio'], rentMin: 3000, nearRer: true);

        $c->clearFilters();

        self::assertSame([], $c->arrondissements);
        self::assertSame([], $c->bedrooms);
        self::assertNull($c->rentMin);
        self::assertFalse($c->nearRer);
        self::assertSame(0, $c->getMoreFiltersCount());
        self::assertNull($c->pingLat);
    }
}
