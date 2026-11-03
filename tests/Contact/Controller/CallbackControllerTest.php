<?php

declare(strict_types=1);

namespace App\Tests\Contact\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CallbackControllerTest extends WebTestCase
{
    public function testItRendersAFocusedPageWithoutHeaderNorFooter(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/on-vous-rappelle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Réservez un appel avec un expert');
        self::assertSelectorNotExists('header');
        self::assertSelectorNotExists('footer');
        self::assertSelectorNotExists('[data-testid="callback-bubble"]');
        // The "short stay / long-term rental" question was removed from the form
        self::assertSelectorNotExists('input[name="callback[stayType]"]');
        // The visitor can still leave the page
        self::assertSelectorExists('main a[href="/"]');
        self::assertStringEndsWith('/fr/on-vous-rappelle', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringEndsWith('/en/request-a-callback', (string) $crawler->filter('link[hreflang="en"]')->attr('href'));
    }

    public function testItShowsTheGoogleRatingAndLinksThePrivacyPolicyFromTheConsent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/on-vous-rappelle');

        self::assertSelectorTextContains('[data-testid="callback-trust"]', '4,9/5');
        self::assertSelectorTextContains('[data-testid="callback-trust"]', 'avis Google');
        self::assertSelectorExists('a[data-testid="callback-trust"][href^="https://share.google/"][target="_blank"][rel~="noopener"]');
        $privacy = $crawler->filter('form[name="callback"] a[href="/fr/politique-de-confidentialite"]');
        self::assertCount(1, $privacy);
        self::assertSame('politique de confidentialité', trim($privacy->text()));
        self::assertSame('_blank', $privacy->attr('target'));
        // One sentence: the link sits in the consent wording
        self::assertStringContainsString('au sujet de ma demande, conformément à notre politique de confidentialité.', $privacy->closest('label')?->text() ?? '');
    }

    public function testSocialSharingTagsAreAbsoluteAndEscapedOnce(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/on-vous-rappelle');

        // Social networks ignore relative image URLs
        self::assertStringStartsWith('https://', (string) $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertStringStartsWith('https://', (string) $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        self::assertSame(
            (string) $crawler->filter('meta[name="description"]')->attr('content'),
            (string) $crawler->filter('meta[property="og:description"]')->attr('content'),
        );
    }

    public function testTheFaqLinksToThePageForInternalLinking(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/fr/faq');
        self::assertSame('demander à être rappelé', trim($crawler->filter('main a[href="/fr/on-vous-rappelle"]')->first()->text()));

        $crawler = $client->request('GET', '/en/faq');
        self::assertSame('request a callback', trim($crawler->filter('main a[href="/en/request-a-callback"]')->first()->text()));
    }

    public function testMetaDescriptionsFitInSearchResults(): void
    {
        $client = static::createClient();
        foreach (['/fr/on-vous-rappelle', '/en/request-a-callback'] as $url) {
            $crawler = $client->request('GET', $url);
            $length = mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content'));
            self::assertGreaterThanOrEqual(110, $length, $url);
            self::assertLessThanOrEqual(155, $length, $url);
        }
    }

    public function testItExistsInEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/request-a-callback');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Book a call with an expert');
        self::assertSelectorNotExists('header');
    }

    public function testEveryHeaderCallbackButtonLeadsToTheContactPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        // Desktop button, mobile menu button and the mobile bubble (footer excluded)
        $ctas = $crawler->filter('a')->reduce(static fn ($a): bool => str_contains($a->text(), 'On vous rappelle') && null === $a->closest('footer'));
        self::assertGreaterThanOrEqual(3, $ctas->count());
        self::assertSame(array_fill(0, $ctas->count(), '/fr/contact'), $ctas->each(static fn ($cta): ?string => $cta->attr('href')));
    }

    public function testItIsLinkedFromTheFooterAndBothSitemaps(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/fr/faq');
        // Listed right before "Contactez-nous"
        $links = $crawler->filter('footer a')->each(static fn ($a): string => (string) $a->attr('href'));
        $callback = array_search('/fr/on-vous-rappelle', $links, true);
        self::assertNotFalse($callback);
        self::assertSame('/fr/contact', $links[$callback + 1]);

        $client->request('GET', '/fr/sitemap');
        self::assertSelectorTextContains('main a[href="/fr/on-vous-rappelle"]', 'On vous rappelle');

        $client->request('GET', '/sitemap.pages.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/fr/on-vous-rappelle</loc>', $xml);
        self::assertStringContainsString('/en/request-a-callback</loc>', $xml);
    }

    public function testFooterAboutColumnListsPricingFirstPropertiesThenAboutLast(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        $links = $crawler->filter('footer a')->each(static fn ($a): string => (string) $a->attr('href'));
        $column = array_values(array_intersect($links, ['/fr/tarifs', '/fr/newsletter/rejoindre', '/fr/blog', '/fr/nos-biens', '/fr/a-propos']));
        self::assertSame(['/fr/tarifs', '/fr/newsletter/rejoindre', '/fr/blog', '/fr/nos-biens', '/fr/a-propos'], $column);

        // Each link keeps its own label: "Nos biens" carries its count badge, "À propos" is not swallowed by it
        self::assertMatchesRegularExpression('/^Nos biens\s*\d*$/', trim($crawler->filter('footer a[href="/fr/nos-biens"]')->text()));
        self::assertSame('À propos', trim($crawler->filter('footer a[href="/fr/a-propos"]')->text()));
        self::assertCount(0, $crawler->filter('footer a a'));
    }

    public function testFooterCallbackLinkCarriesASoftAvailabilityPing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        $link = $crawler->filter('footer a[href="/fr/on-vous-rappelle"]');
        self::assertSame('On vous rappelle', trim($link->text()));
        $ping = $link->filter('span[aria-hidden="true"] span.motion-safe\\:animate-ping');
        self::assertCount(1, $ping, 'Decorative ping, animated only without reduced motion');
    }
}
