<?php

namespace App\Tests\Marketplace\Filter;

use App\Marketplace\Domain\Property;
use App\Marketplace\Filter\PropertyFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The arrondissement filter is multi-select: a property is kept when its postal
 * code matches any of the selected arrondissements (logical OR).
 */
final class PropertyFilterTest extends KernelTestCase
{
    public function testKeepsPropertiesInAnySelectedArrondissement(): void
    {
        $filter = self::getContainer()->get(PropertyFilter::class);

        $result = $filter->apply(
            [$this->property('75007'), $this->property('75016'), $this->property('75011')],
            [7, 16],
        );

        $postalCodes = array_map(static fn (Property $p) => $p->address['postalCode'], $result);
        sort($postalCodes);

        $this->assertSame(['75007', '75016'], $postalCodes);
    }

    public function testEmptySelectionKeepsEveryProperty(): void
    {
        $filter = self::getContainer()->get(PropertyFilter::class);

        $result = $filter->apply(
            [$this->property('75001'), $this->property('75020')],
            [],
        );

        $this->assertCount(2, $result);
    }

    private function property(string $postalCode): Property
    {
        return new Property(
            id: 'prop-'.$postalCode,
            address: ['city' => 'Paris', 'postalCode' => $postalCode],
        );
    }
}
