<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every public page embeds a schema.org graph (Organization + WebSite + a
 * per-page node). A missing comma between nodes silently breaks the whole
 * graph for search engines, so each page's JSON-LD must parse.
 */
final class SchemaJsonLdTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function publicPages(): iterable
    {
        yield 'home' => ['/', 'WebPage'];
        yield 'contact' => ['/fr/contact', 'ContactPage'];
        yield 'faq' => ['/fr/faq', 'FAQPage'];
        yield 'about' => ['/fr/a-propos', 'AboutPage'];
        yield 'pricing' => ['/fr/tarifs', 'Service'];
        yield 'find accommodation' => ['/fr/services/trouver-un-logement', 'Service'];
        yield 'companies' => ['/fr/services/pour-les-entreprises', 'Service'];
        yield 'landlords' => ['/fr/services/gestion-locative-paris', 'Service'];
        yield 'find tenant' => ['/fr/services/trouver-un-locataire', 'Service'];
        yield 'list property' => ['/fr/proposer-un-bien', 'Service'];
        yield 'newsletter' => ['/fr/newsletter/rejoindre', 'WebPage'];
        yield 'legal notice' => ['/fr/mentions-legales', 'WebPage'];
        yield 'privacy policy' => ['/fr/politique-de-confidentialite', 'WebPage'];
        yield 'terms' => ['/fr/conditions-generales-de-vente', 'WebPage'];
        yield 'sitemap' => ['/fr/sitemap', 'WebPage'];
        yield 'contact (en)' => ['/en/contact', 'ContactPage'];
    }

    #[DataProvider('publicPages')]
    public function testItEmbedsAValidJsonLdGraphWithThePageNode(string $path, string $expectedType): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        $scripts = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThan(0, $scripts->count(), sprintf('%s must embed a JSON-LD block.', $path));

        $graph = json_decode($scripts->first()->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($graph);
        self::assertArrayHasKey('@graph', $graph);

        $types = array_map(static fn (array $node): mixed => $node['@type'] ?? null, $graph['@graph']);
        self::assertContains('ProfessionalService', $types, sprintf('%s must embed the organization node.', $path));
        self::assertContains($expectedType, $types, sprintf('%s must embed a %s node.', $path, $expectedType));
    }
}
