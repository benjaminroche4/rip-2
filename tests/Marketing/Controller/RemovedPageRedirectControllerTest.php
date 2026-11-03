<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Trouver un locataire" and "Proposer un bien" were removed (Nov. 2026):
 * their URLs answer a 301 to the property management page and are listed nowhere.
 */
final class RemovedPageRedirectControllerTest extends WebTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function removedUrls(): iterable
    {
        yield 'find tenant (fr)' => ['/fr/services/trouver-un-locataire', '/fr/services/gestion-locative-paris'];
        yield 'find tenant (en)' => ['/en/services/find-a-tenant', '/en/services/property-management-paris'];
        yield 'list property (fr)' => ['/fr/proposer-un-bien', '/fr/services/gestion-locative-paris'];
        yield 'list property (en)' => ['/en/list-your-property', '/en/services/property-management-paris'];
    }

    #[DataProvider('removedUrls')]
    public function testRemovedPageRedirectsPermanentlyToPropertyManagement(string $url, string $target): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($target);
    }

    public function testRemovedPagesAreListedNowhere(): void
    {
        $client = static::createClient();

        foreach (['/fr/sitemap', '/fr/faq', '/fr/services/gestion-locative-paris'] as $page) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            self::assertCount(0, $crawler->filter('a[href="/fr/services/trouver-un-locataire"], a[href="/fr/proposer-un-bien"]'), $page);
        }

        $client->request('GET', '/sitemap.pages.xml');
        $xml = (string) $client->getResponse()->getContent();
        foreach (['trouver-un-locataire', 'find-a-tenant', 'proposer-un-bien', 'list-your-property'] as $slug) {
            self::assertStringNotContainsString($slug, $xml);
        }
    }

    public function testListingFormPostIsGone(): void
    {
        $client = static::createClient();
        $client->request('POST', '/fr/proposer-un-bien');

        // The route only redirects GET/HEAD: the former form submission no longer exists
        self::assertResponseStatusCodeSame(405);
    }
}
