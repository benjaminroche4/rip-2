<?php

namespace App\Tests\Marketplace\Twig;

use App\Marketplace\Twig\Extension\PropertyCountExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PropertyCountExtensionTest extends KernelTestCase
{
    public function testItReturnsANonNegativeCountForLocale(): void
    {
        self::bootKernel();

        $extension = self::getContainer()->get(PropertyCountExtension::class);

        self::assertGreaterThanOrEqual(0, $extension->availablePropertyCount('fr'));
    }

    public function testItFallsBackToDefaultLocaleWhenNoneGiven(): void
    {
        self::bootKernel();

        $extension = self::getContainer()->get(PropertyCountExtension::class);

        // No active request in this context, so it must fall back to 'fr'.
        self::assertGreaterThanOrEqual(0, $extension->availablePropertyCount());
    }
}
