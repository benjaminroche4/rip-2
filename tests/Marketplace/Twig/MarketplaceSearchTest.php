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
            rentMax: 15000,                     // above ceiling -> "no maximum"
            features: ['balcony', 'unicorn'],   // unicorn not whitelisted
            availability: 'someday',            // invalid -> null
        );

        self::assertSame([3, 7], $c->arrondissements);
        self::assertSame(['studio'], $c->bedrooms);
        self::assertSame(['yes'], $c->furnished);
        self::assertNull($c->rentMax);
        self::assertSame(['balcony'], $c->features);
        self::assertNull($c->availability);
    }

    public function testSelectAreaSelectsArrondissementsAndPinsTheMap(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');

        $c->selectArea('3,4');

        self::assertSame([3, 4], $c->arrondissements);
        // Drafts are strings so the checkbox toggle can untick a server-set value.
        self::assertSame(['3', '4'], $c->draftArrondissements);
        self::assertNotNull($c->pingLat);
        self::assertNotNull($c->pingLng);
    }

    public function testSearchAddressPinsExactLocationAndFiltersArrondissement(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');

        $c->searchAddress(48.8606, 2.3376, 1);

        self::assertSame([1], $c->arrondissements);
        self::assertSame(['1'], $c->draftArrondissements);
        self::assertSame(48.8606, $c->pingLat);
        self::assertSame(2.3376, $c->pingLng);
        self::assertTrue($c->pingExplicit);
        self::assertSame(16.0, $c->zoom);
        self::assertSame(1, $c->page);
    }

    public function testSearchAddressReplacesPriorArrondissementSelection(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [5, 8, 12]);

        // A picked address in the 1st arrondissement must become the sole location
        // filter, dropping the previously selected arrondissements.
        $c->searchAddress(48.8606, 2.3376, 1);

        self::assertSame([1], $c->arrondissements);
        self::assertSame(['1'], $c->draftArrondissements);
        self::assertTrue($c->pingExplicit);
    }

    public function testSearchAddressWithoutPostalCodeResolvesNearestArrondissement(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [12]);

        // No postal code (arr 0): the pin sits in the Latin Quarter, so the
        // nearest arrondissement (5th) is selected and the stale [12] is dropped.
        $c->searchAddress(48.8443, 2.3500, 0);

        self::assertSame([5], $c->arrondissements);
        self::assertSame(['5'], $c->draftArrondissements);
        self::assertTrue($c->pingExplicit);
        self::assertSame(48.8443, $c->pingLat);
    }

    public function testUntickingTheAddressArrondissementRemovesItFromTheField(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');
        $c->draftQ = '12 Rue de Rivoli, 75001 Paris, France';
        $c->searchAddress(48.8606, 2.3376, 1);

        self::assertSame([1], $c->arrondissements);
        self::assertSame(1, $c->pinnedArrondissement);

        // The user keeps the 8th but unticks the 1st (the address arrondissement).
        // Checkboxes submit string values.
        $c->draftArrondissements = ['8'];
        $c->search();

        self::assertSame([8], $c->arrondissements);
        self::assertNull($c->draftQ);
        self::assertNull($c->pinnedArrondissement);
        self::assertFalse($c->pingExplicit);
    }

    public function testKeepingTheAddressArrondissementPreservesThePinAndIgnoresTheLabel(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');
        $c->draftQ = '12 Rue de Rivoli, 75001 Paris, France';
        $c->searchAddress(48.8606, 2.3376, 1);

        // Broaden to the 8th while keeping the address arrondissement (1st).
        $c->draftArrondissements = ['1', '8'];
        $c->search();

        self::assertSame([1, 8], $c->arrondissements);
        // The address label is a location, not a free-text filter.
        self::assertNull($c->q);
        self::assertSame('12 Rue de Rivoli, 75001 Paris, France', $c->draftQ);
        self::assertTrue($c->pingExplicit);
    }

    public function testClearPropertyTypeResetsOnlyTheTypeDrafts(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [3]);
        $c->draftBedrooms = ['2'];
        $c->draftFurnished = ['yes'];
        $c->draftLongTerm = true;
        $c->draftMidTerm = true;

        $c->clearPropertyType();

        self::assertSame([], $c->draftBedrooms);
        self::assertSame([], $c->draftFurnished);
        self::assertFalse($c->draftLongTerm);
        self::assertFalse($c->draftMidTerm);
        // Other draft groups are untouched.
        self::assertSame(['3'], $c->draftArrondissements);
    }

    public function testSearchAppliesDraftsAndResetsPagination(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');
        $c->page = 5;

        $c->draftBedrooms = ['2'];
        $c->draftFurnished = ['no'];
        $c->draftLongTerm = true;
        $c->draftRentMax = 2500;

        $c->search();

        self::assertSame(['2'], $c->bedrooms);
        self::assertSame(['no'], $c->furnished);
        self::assertTrue($c->longTerm);
        self::assertSame(2500, $c->rentMax);
        self::assertSame(1, $c->page);
    }

    public function testMoreFiltersCountReflectsActiveMoreFilters(): void
    {
        $c = $this->component();
        $c->mount(
            locale: 'fr',
            rentMax: 3000,
            features: ['balcony', 'parking'],
            availability: 'now',
            nearMetro: true,
        );

        // rentMax (1) + 2 features + availability (1) + nearMetro (1) = 5
        self::assertSame(5, $c->getMoreFiltersCount());
    }

    public function testClearFiltersResetsEverything(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr', arrondissements: [3], bedrooms: ['studio'], rentMax: 3000, nearRer: true);

        $c->clearFilters();

        self::assertSame([], $c->arrondissements);
        self::assertSame([], $c->bedrooms);
        self::assertNull($c->rentMax);
        // Boolean filters reset to null (off) so they never linger in the URL.
        self::assertNull($c->nearRer);
        self::assertSame(0, $c->getMoreFiltersCount());
        self::assertNull($c->pingLat);
    }

    public function testBooleanFiltersAreNullWhenOffSoTheyStayOutOfTheUrl(): void
    {
        $c = $this->component();
        $c->mount(locale: 'fr');

        // A search with the boolean drafts left off must store null, not false.
        $c->draftLongTerm = false;
        $c->draftNearMetro = false;
        $c->search();

        self::assertNull($c->longTerm);
        self::assertNull($c->nearMetro);

        // Turning one on stores true.
        $c->draftLongTerm = true;
        $c->search();

        self::assertTrue($c->longTerm);
    }
}
