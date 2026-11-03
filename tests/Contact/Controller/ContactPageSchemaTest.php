<?php

declare(strict_types=1);

namespace App\Tests\Contact\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContactPageSchemaTest extends WebTestCase
{
    /** @return list<array<string, mixed>> */
    private static function graph(Crawler $crawler): array
    {
        $nodes = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            array_push($nodes, ...($data['@graph'] ?? [$data]));
        }

        return $nodes;
    }

    public function testContactPageSchemaUsesTheCanonicalDomainAndTheSiteWideOrganization(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/contact');

        self::assertResponseIsSuccessful();
        $nodes = self::graph($crawler);
        $contactPage = array_values(array_filter($nodes, static fn (array $n): bool => 'ContactPage' === ($n['@type'] ?? null)))[0];
        $organization = array_values(array_filter($nodes, static fn (array $n): bool => 'ProfessionalService' === ($n['@type'] ?? null)))[0];

        // Built from site_url like the canonical, never from the request host (localhost in tests)
        self::assertSame($crawler->filter('link[rel="canonical"]')->attr('href'), $contactPage['url']);
        // One business entity on the site: the ContactPage points to it
        self::assertSame(['@id' => $organization['@id']], $contactPage['mainEntity']);
        self::assertCount(0, array_filter($nodes, static fn (array $n): bool => 'LocalBusiness' === ($n['@type'] ?? null)));
    }

    public function testCustomersAreOnlyOfferedTheFormAndThePhone(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/contact');

        $organization = array_values(array_filter(self::graph($crawler), static fn (array $n): bool => 'ProfessionalService' === ($n['@type'] ?? null)))[0];
        foreach ($organization['contactPoint'] as $contactPoint) {
            self::assertStringNotContainsString('wa.me', $contactPoint['url'] ?? '');
        }

        $crawler = $client->request('GET', '/fr/faq');
        self::assertSelectorTextContains('section#relocation', 'Par téléphone ou via notre formulaire de contact');
        self::assertSelectorTextNotContains('section#relocation', 'WhatsApp');
    }
}
