<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PropertyEstimationControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/services/gestion-locative-paris');

        self::assertResponseIsSuccessful();
    }

    public function testTheFaqShowsTheFiveKeyOwnerQuestionsAndLinksToTheGeneralFaq(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/gestion-locative-paris');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'Gérez-vous les biens en courte, moyenne et longue durée ?',
            'Quels profils de locataires proposez-vous ?',
            'Étudiez-vous une stratégie pour optimiser mes revenus ?',
            'Y a-t-il un engagement de durée ?',
            'Dois-je avancer des frais ?',
        ], $crawler->filter('#accordion-1 h3')->each(static fn ($h): string => trim($h->text())));

        // Internal linking: general FAQ (on its property management topic), estimation form, contact
        self::assertCount(1, $crawler->filter('main a[href="/fr/faq#property-management"]'));
        self::assertGreaterThan(0, $crawler->filter('#accordion-1 a[href="/fr/services/gestion-locative-paris#estimation-card"]')->count());
        self::assertGreaterThan(0, $crawler->filter('#accordion-1 a[href="/fr/contact"]')->count());
        self::assertCount(1, $crawler->filter('#estimation-card'), 'The FAQ link targets the estimation card.');
        self::assertGreaterThan(0, $crawler->filter('#accordion-1 a[href="/fr/services/pour-les-entreprises"]')->count());
    }

    public function testItSellsThePremiumTenantsFromTheRelocationBusiness(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/gestion-locative-paris');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 + p, main', 'locataires premium');
        $block = $crawler->filter('[data-testid="premium-tenants"]');
        self::assertCount(1, $block);
        self::assertCount(3, $block->filter('h3'));
        self::assertCount(1, $block->filter('a[href="/fr/services/pour-les-entreprises"]'), 'The corporate card links to the corporate offer.');
    }
}
