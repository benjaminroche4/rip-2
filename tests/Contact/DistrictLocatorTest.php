<?php

declare(strict_types=1);

namespace App\Tests\Contact;

use App\Contact\Domain\DistrictLocator;
use PHPUnit\Framework\TestCase;

/**
 * Ray-casting against the real ParisDistricts outlines: well-centred points
 * of known districts must resolve, anything far outside must return null.
 */
final class DistrictLocatorTest extends TestCase
{
    public function testItResolvesPointsInsideKnownDistricts(): void
    {
        // Montmartre, en plein 18e.
        self::assertSame('18e', DistrictLocator::locate(48.8867, 2.3431));
        // Rue de la Folie-Mericourt, 11e.
        self::assertSame('11e', DistrictLocator::locate(48.8631, 2.3708));
        // Palais-Royal, 1er.
        self::assertSame('1er', DistrictLocator::locate(48.8625, 2.3364));
        // Bercy, 12e.
        self::assertSame('12e', DistrictLocator::locate(48.8353, 2.3862));
        // Boulogne-Billancourt, petite couronne 92.
        self::assertSame('92', DistrictLocator::locate(48.8352, 2.2409));
    }

    public function testItReturnsNullOutsideEveryOutline(): void
    {
        // Lyon : hors de Paris et de la petite couronne.
        self::assertNull(DistrictLocator::locate(45.75, 4.85));
        // Plein océan.
        self::assertNull(DistrictLocator::locate(0.0, 0.0));
    }
}
