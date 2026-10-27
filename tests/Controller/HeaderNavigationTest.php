<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Desktop header navigation. Guards the structure of the main nav:
 *  - direct links to "Property search" and "Pricing"
 *  - a "Management & services" dropdown exposing the three service pages.
 */
final class HeaderNavigationTest extends WebTestCase
{
    public function testDesktopNavExposesTopLevelLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        self::assertGreaterThan(
            0,
            $crawler->filter('header a[href="/fr/services/trouver-un-logement"]')->count(),
            'Header must link to the find-accommodation page.'
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('header a[href="/fr/tarifs"]')->count(),
            'Header must link to the pricing page.'
        );
    }

    public function testDesktopNavHasSolutionsDropdownWithServiceLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        // The dropdown trigger and its popover are present.
        self::assertGreaterThan(
            0,
            $crawler->filter('header button[popovertarget="desktop-menu-solutions"]')->count(),
            'Header must expose the solutions dropdown trigger.'
        );
        self::assertSelectorExists('#desktop-menu-solutions');

        // The dropdown links to the three service pages.
        $popover = $crawler->filter('#desktop-menu-solutions');
        self::assertGreaterThan(0, $popover->filter('a[href="/fr/services/gestion-locative-paris"]')->count());
        self::assertGreaterThan(0, $popover->filter('a[href="/fr/services/trouver-un-locataire"]')->count());
        self::assertGreaterThan(0, $popover->filter('a[href="/fr/services/pour-les-entreprises"]')->count());
    }

    public function testMobileCallbackBannerLinksToContactPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $banner = $crawler->filter('[data-testid="callback-banner"]');
        self::assertCount(1, $banner, 'The call-back banner must be rendered once above the header.');
        self::assertSame('/fr/contact', $banner->attr('href'));
        self::assertStringContainsString('lg:hidden', (string) $banner->attr('class'), 'The banner is mobile-only.');
        self::assertStringContainsString('On vous rappelle', $banner->text());
    }

    public function testMobileCallbackBannerIsHiddenOnContactPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/contact');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="callback-banner"]'), 'No call-back banner on the page it links to.');
    }
}
