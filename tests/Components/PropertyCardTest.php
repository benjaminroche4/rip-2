<?php

namespace App\Tests\Components;

use App\Marketplace\Domain\Property;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders Marketplace/PropertyCard with mock Property DTOs to lock in:
 * - the rented/under-offer/available status badge logic
 * - the canonical URL anchor (id="property-<slug>")
 * - the data-property-id attribute consumed by the map-markers controller
 *
 * The component is included from MarketplaceSearch and from
 * MarketplaceController::propertyCardFragment, so a regression breaks the
 * whole listing UX.
 */
final class PropertyCardTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersCanonicalAnchorAndPropertyId(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyCard', [
            'property' => $this->property(slug: 'mon-bel-appart-paris-11'),
            'locale' => 'fr',
        ]);

        $this->assertStringContainsString('id="property-mon-bel-appart-paris-11"', $html);
        $this->assertStringContainsString('data-property-id="abc123"', $html);
        $this->assertStringContainsString('mouseenter->map-markers#highlightMarker', $html);
    }

    public function testRentedStatusRendersRentedBadge(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyCard', [
            'property' => $this->property(status: 'rented'),
            'locale' => 'fr',
        ]);

        $this->assertStringContainsString('bg-red-50 border-red-100 text-red-600', $html);
    }

    public function testUnderOfferStatusRendersAmberBadge(): void
    {
        $html = (string) $this->renderTwigComponent('Marketplace:PropertyCard', [
            'property' => $this->property(status: 'underOffer'),
            'locale' => 'fr',
        ]);

        $this->assertStringContainsString('bg-amber-50 border-amber-100 text-amber-600', $html);
    }

    private function property(
        string $id = 'abc123',
        ?string $slug = 'sample-slug',
        ?string $status = 'available',
    ): Property {
        return new Property(
            id: $id,
            createdAt: new \DateTimeImmutable('-1 month'),
            title: 'Studio meublé Paris 11e',
            bedrooms: '1',
            monthlyRent: 1500,
            status: $status,
            slug: $slug,
            address: ['city' => 'Paris', 'postalCode' => '75011', 'street' => '12 rue Test'],
            mainPhoto: ['url' => 'https://example.test/photo.jpg', 'alt' => 'Salon'],
            photos: [['url' => 'https://example.test/photo.jpg', 'alt' => 'Salon']],
            location: ['lat' => 48.86, 'lng' => 2.37],
            squareMeters: 30,
            propertyTypeName: 'Studio',
            propertyTypeSlug: 'studio',
            listingTypeName: 'Location',
            bedroomsLabel: '1',
            furnished: 'yes',
        );
    }
}
