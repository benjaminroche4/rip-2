<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEO guard: hreflang alternates must point to the real localized route paths
 * (/fr/nos-biens <-> /en/our-properties), not naive locale-prefix substitution
 * which produced 404 alternates (/en/nos-biens).
 */
final class HreflangTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function localizedPathProvider(): iterable
    {
        yield 'marketplace' => ['/fr/nos-biens', '/fr/nos-biens', '/en/our-properties'];
        yield 'about us' => ['/fr/a-propos', '/fr/a-propos', '/en/about-us'];
        yield 'pricing' => ['/fr/tarifs', '/fr/tarifs', '/en/pricing'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localizedPathProvider')]
    public function testItEmitsLocalizedHreflangAlternates(string $requestPath, string $expectedFrPath, string $expectedEnPath): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $requestPath);

        self::assertResponseIsSuccessful();

        $frHref = $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href');
        $enHref = $crawler->filter('link[rel="alternate"][hreflang="en"]')->attr('href');
        $xDefaultHref = $crawler->filter('link[rel="alternate"][hreflang="x-default"]')->attr('href');

        self::assertStringEndsWith($expectedFrPath, (string) $frHref);
        self::assertStringEndsWith($expectedEnPath, (string) $enHref);
        self::assertSame($frHref, $xDefaultHref);
    }
}
