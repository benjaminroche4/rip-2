<?php

namespace App\Tests\Marketplace\Filter;

use App\Marketplace\Domain\Property;
use App\Marketplace\Filter\PropertyFilter;
use App\Marketplace\Filter\PropertySearchCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Every criterion maps to a concrete Sanity field; filtering happens in PHP on
 * the cached property list. Each filter is covered with a match and a no-match.
 */
final class PropertyFilterTest extends TestCase
{
    private PropertyFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new PropertyFilter();
    }

    /** @return array<int, string> */
    private function ids(array $result): array
    {
        return array_map(static fn (Property $p) => $p->id, $result);
    }

    public function testEmptyCriteriaKeepsEveryProperty(): void
    {
        $result = $this->filter->apply(
            [$this->property('a'), $this->property('b')],
            new PropertySearchCriteria(),
        );

        $this->assertCount(2, $result);
    }

    public function testKeepsPropertiesInAnySelectedArrondissement(): void
    {
        $result = $this->filter->apply(
            [
                $this->property('7', postalCode: '75007'),
                $this->property('16', postalCode: '75016'),
                $this->property('11', postalCode: '75011'),
            ],
            new PropertySearchCriteria(arrondissements: [7, 16]),
        );

        $this->assertSame(['7', '16'], $this->ids($result));
    }

    public function testFreeTextMatchesTitleAddressOrTags(): void
    {
        $byTitle = $this->property('title', title: 'Bright loft near Bastille');
        $byStreet = $this->property('street', street: 'Rue de Rivoli');
        $byTag = $this->property('tag', tags: ['Luxury furnished apartment Paris']);
        $other = $this->property('other', title: 'Studio', street: 'Avenue Foch');

        $all = [$byTitle, $byStreet, $byTag, $other];

        $this->assertSame(['title'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(q: 'bastille'))));
        $this->assertSame(['street'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(q: 'RIVOLI'))));
        $this->assertSame(['tag'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(q: 'luxury'))));
        $this->assertSame([], $this->ids($this->filter->apply($all, new PropertySearchCriteria(q: 'nonexistent'))));
    }

    public function testBedroomsIsMultiSelect(): void
    {
        $result = $this->filter->apply(
            [
                $this->property('studio', bedrooms: 'studio'),
                $this->property('one', bedrooms: '1'),
                $this->property('three', bedrooms: '3'),
            ],
            new PropertySearchCriteria(bedrooms: ['studio', '3']),
        );

        $this->assertSame(['studio', 'three'], $this->ids($result));
    }

    public function testFurnishedFilter(): void
    {
        $all = [$this->property('y', furnished: 'yes'), $this->property('n', furnished: 'no')];

        $this->assertSame(['y'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(furnished: ['yes']))));
        $this->assertSame(['y', 'n'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(furnished: ['yes', 'no']))));
    }

    public function testLongAndMidTermFilters(): void
    {
        $all = [
            $this->property('long', longTerm: true, midTerm: false),
            $this->property('mid', longTerm: false, midTerm: true),
        ];

        $this->assertSame(['long'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(longTerm: true))));
        $this->assertSame(['mid'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(midTerm: true))));
    }

    public function testRentMinFilter(): void
    {
        $all = [$this->property('cheap', rent: 1200), $this->property('pricey', rent: 3000)];

        $this->assertSame(['pricey'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(rentMin: 2000))));
    }

    public function testFeaturesRequireEveryRequestedEquipment(): void
    {
        $both = $this->property('both', equipment: ['balcony' => true, 'elevator' => true]);
        $onlyBalcony = $this->property('one', equipment: ['balcony' => true, 'elevator' => false]);

        $all = [$both, $onlyBalcony];

        $this->assertSame(['both', 'one'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(features: ['balcony']))));
        $this->assertSame(['both'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(features: ['balcony', 'elevator']))));
    }

    public function testAvailabilityUsesInjectedNow(): void
    {
        $now = new \DateTimeImmutable('2026-06-01');
        $soon = $this->property('soon', availableDate: new \DateTimeImmutable('2026-06-20'));
        $later = $this->property('later', availableDate: new \DateTimeImmutable('2026-09-01'));
        $past = $this->property('past', availableDate: new \DateTimeImmutable('2026-05-01'));

        $all = [$soon, $later, $past];

        $this->assertSame(['past'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(availability: 'now'), $now)));
        $this->assertSame(['soon', 'past'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(availability: '30days'), $now)));
    }

    public function testNearMetroAndRerFilters(): void
    {
        $all = [
            $this->property('metro', metro: ['8', '12']),
            $this->property('rer', rer: ['C']),
            $this->property('none'),
        ];

        $this->assertSame(['metro'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(nearMetro: true))));
        $this->assertSame(['rer'], $this->ids($this->filter->apply($all, new PropertySearchCriteria(nearRer: true))));
    }

    /**
     * @param array<int, string>      $tags
     * @param array<string, bool>     $equipment
     * @param array<int, string>      $metro
     * @param array<int, string>      $rer
     */
    private function property(
        string $id,
        ?string $title = null,
        ?string $street = null,
        ?string $postalCode = '75001',
        ?string $bedrooms = null,
        ?string $furnished = null,
        ?bool $longTerm = null,
        ?bool $midTerm = null,
        ?int $rent = null,
        array $equipment = [],
        ?\DateTimeImmutable $availableDate = null,
        array $metro = [],
        array $rer = [],
        array $tags = [],
    ): Property {
        return new Property(
            id: $id,
            title: $title,
            bedrooms: $bedrooms,
            monthlyRent: $rent,
            longTerm: $longTerm,
            midTerm: $midTerm,
            address: ['city' => 'Paris', 'postalCode' => $postalCode, 'street' => $street],
            availableDate: $availableDate,
            equipment: $equipment ?: null,
            furnished: $furnished,
            metro: $metro ?: null,
            rer: $rer ?: null,
            tags: $tags ?: null,
        );
    }
}
