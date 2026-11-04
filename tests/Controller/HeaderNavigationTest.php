<?php

namespace App\Tests\Controller;

use App\Blog\Repository\BlogRepository;
use App\Shared\Sanity\SanityService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Header navigation. Guards the structure of the main nav, desktop and mobile:
 *  - "Property search", "Pricing", then a "Resources" dropdown
 *  - the dropdown exposes blog, FAQ and about only (service pages are not in the header).
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

    public function testTopLevelEntriesAreInOrderOnDesktop(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $entries = $crawler->filter('header el-popover-group > a, header el-popover-group > button')
            ->each(static fn ($node): string => trim(preg_replace('/\s+/', ' ', $node->text())));
        self::assertSame(['Recherche de logement', 'Tarifs', 'Ressources'], $entries);
    }

    public function testHeaderPopoversAreClosedWhenStickyHeaderScrollsAway(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('header[data-controller~="sticky-header"]'));
        foreach (['#desktop-menu-solutions', '#desktop-lang'] as $popover) {
            self::assertCount(
                1,
                $crawler->filter('header[data-controller~="sticky-header"] '.$popover.'[data-sticky-header-target="popover"]'),
                $popover.' must be a sticky-header target, otherwise it could stay on screen alone if the header ever scrolls away.'
            );
        }
    }

    public function testResourcesDropdownListsBlogFaqAndAboutOnDesktopAndMobile(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $expected = [
            '/fr/blog',
            '/fr/faq',
            '/fr/a-propos',
        ];
        foreach (['#desktop-menu-solutions', '#mobile-menu el-disclosure#resources'] as $panel) {
            $links = $crawler->filter($panel.' a')->each(static fn ($a): string => (string) $a->attr('href'));
            self::assertSame($expected, $links, $panel);
        }

        // Mobile: same top-level order, "About" now lives inside Resources only
        $mobileTop = $crawler->filter('#mobile-menu a[href="/fr/services/trouver-un-logement"], #mobile-menu a[href="/fr/tarifs"], #mobile-menu button[commandfor="resources"]')
            ->each(static fn ($node): string => trim(preg_replace('/\s+/', ' ', $node->text())));
        self::assertSame(['Recherche de logement Sur-mesure', 'Tarifs', 'Ressources'], $mobileTop);
        self::assertCount(1, $crawler->filter('#mobile-menu a[href="/fr/a-propos"]'));

        // Service pages are no longer in the header (desktop nor mobile); the footer still links them
        foreach (['gestion-locative-paris', 'pour-les-entreprises'] as $service) {
            self::assertCount(0, $crawler->filter(sprintf('header a[href="/fr/services/%1$s"], #mobile-menu a[href="/fr/services/%1$s"]', $service)));
            self::assertCount(1, $crawler->filter(sprintf('footer a[href="/fr/services/%s"]', $service)));
        }
    }

    public function testResourcesTriggerIsHighlightedOnTheFaq(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertStringContainsString('text-primary', (string) $crawler->filter('header button[popovertarget="desktop-menu-solutions"]')->attr('class'));
        self::assertSame('page', $crawler->filter('#desktop-menu-solutions a[href="/fr/faq"]')->attr('aria-current'));
    }

    public function testMobileHeaderHasACallbackBubbleNextToTheMenuButton(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        $bubble = $crawler->filter('header [data-testid="callback-bubble"]');
        self::assertCount(1, $bubble);
        self::assertSame('/fr/contact', $bubble->attr('href'));
        self::assertStringContainsString('rounded-full', (string) $bubble->attr('class'));
        self::assertStringContainsString('bg-primary/10', (string) $bubble->attr('class'), 'Soft primary tint, not the solid brand color');
        self::assertStringContainsString('max-[359px]:hidden', (string) $bubble->attr('class'), 'Hidden on very narrow screens');
        self::assertSame('On vous rappelle', trim($bubble->filter('.sr-only')->text()), 'Accessible name of the icon-only link');
        // Placed right before the menu button, in the mobile-only group
        self::assertCount(1, $crawler->filter('header .lg\\:hidden > [data-testid="callback-bubble"] + button[commandfor="mobile-menu"]'));
        // The former red banner above the header is gone
        self::assertCount(0, $crawler->filter('[data-testid="callback-banner"]'));
    }

    private function createClientWithBlogTotal(int $total): KernelBrowser
    {
        $client = static::createClient();
        // countAll() is cached under a fixed key: start from a clean blog cache
        static::getContainer()->get(TagAwareCacheInterface::class)->invalidateTags([BlogRepository::CACHE_TAG]);

        $sanity = $this->createStub(SanityService::class);
        $sanity->method('query')->willReturnCallback(
            static fn (string $query): mixed => str_starts_with($query, 'count(*[_type == "blog"') ? $total : []
        );
        static::getContainer()->set(SanityService::class, $sanity);

        return $client;
    }

    public function testBlogCardShowsThePostCountBadgeOnDesktopAndMobile(): void
    {
        $client = $this->createClientWithBlogTotal(163);
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        foreach (['#desktop-menu-solutions', '#mobile-menu el-disclosure#resources'] as $panel) {
            self::assertSelectorTextContains($panel.' a[href="/fr/blog"] p.rounded-full', '163 guides');
        }
        // The FAQ card has its own pill (questions), never the blog count
        self::assertSelectorTextNotContains('#desktop-menu-solutions a[href="/fr/faq"] p.rounded-full', 'guides');
    }

    public function testBlogCardHidesTheBadgeWithoutPosts(): void
    {
        $client = $this->createClientWithBlogTotal(0);
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#desktop-menu-solutions a[href="/fr/blog"] p.rounded-full');
    }

    public function testFaqCardShowsTheQuestionCountAndThePanelHasNoRepeatedTitle(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/blog');

        self::assertResponseIsSuccessful();
        $count = static::getContainer()->get(\App\Marketing\Twig\FaqExtension::class)->questionCount();
        foreach (['#desktop-menu-solutions', '#mobile-menu el-disclosure#resources'] as $panel) {
            self::assertSelectorTextSame($panel.' a[href="/fr/faq"] p.rounded-full', $count.' questions');
        }
        // The panel no longer repeats the "Ressources" label of the button that opened it
        self::assertSelectorTextNotContains('#desktop-menu-solutions', 'Ressources');
    }

    public function testAwardYearIsTheSameOnEveryPage(): void
    {
        $client = static::createClient();
        $year = (string) static::getContainer()->get('twig')->getGlobals()['site_award_year'];

        foreach (['/', '/fr/a-propos', '/fr/services/trouver-un-logement', '/fr/services/gestion-locative-paris'] as $page) {
            $client->request('GET', $page);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Paris en '.$year, $html, $page);
            self::assertDoesNotMatchRegularExpression('/Paris (?:en )?20(?!'.substr($year, 2).')\d\d</', $html, $page);
        }
    }

    public function testHeaderStaysStickyInFrontOfTheContentAndTheFooter(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        $header = $crawler->filter('header[role="banner"]');
        $classes = explode(' ', (string) $header->attr('class'));
        self::assertContains('sticky', $classes);
        // Direct child of <body>: sticky over the whole page, footer included
        self::assertCount(1, $crawler->filter('body > header[role="banner"] ~ main#main-content ~ footer'));
        // Above <main> and the footer (both z-20), so photos or cards never slide over it
        self::assertContains('z-50', $classes);
        self::assertStringContainsString('z-20', (string) $crawler->filter('body > footer')->attr('class'));
    }
}
