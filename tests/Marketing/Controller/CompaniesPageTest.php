<?php

namespace App\Tests\Marketing\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Solutions pour les entreprises" landing page: conversion blocks, SEO basics and translated copy.
 */
final class CompaniesPageTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localizedPages(): iterable
    {
        yield 'fr' => ['/fr/services/pour-les-entreprises', '/fr/contact'];
        yield 'en' => ['/en/services/for-companies', '/en/contact'];
    }

    #[DataProvider('localizedPages')]
    public function testItRendersEveryConversionBlockWithTranslatedCopy(string $path, string $contactPath): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'), 'The page must have a single h1.');
        self::assertCount(1, $crawler->filter('[data-testid="companies-why"]'));
        self::assertCount(4, $crawler->filter('[data-testid="companies-use-cases"] [data-testid="companies-use-case"]'));
        self::assertCount(5, $crawler->filter('[data-process-stepper-target="step"]'));
        self::assertCount(5, $crawler->filter('[id^="accordion-1-item-"][id$="-content"]'), 'The five key HR questions must be listed.');
        self::assertCount(0, $crawler->filter('main [data-reviews-swiper-target="track"]'), 'No reviews carousel on the corporate page.');
        self::assertCount(1, $crawler->filter('[data-testid="companies-hero"] h1'), 'The hero is the standard page header, not a video card.');
        self::assertCount(0, $crawler->filter('main video'));
        self::assertCount(7, $crawler->filter('[data-testid="client-logos"] [data-marquee-target="item"] svg'), 'The 7 client brands are rendered from the brands icon set.');
        self::assertGreaterThanOrEqual(
            2,
            $crawler->filter('main a[href="'.$contactPath.'"]')->count(),
            'Hero and process blocks must both link to the contact page.'
        );

        $body = (string) $client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression('/companies\.[a-zA-Z]+\.[a-zA-Z0-9.]+/', strip_tags($body), 'No raw translation key may leak.');
        self::assertStringNotContainsString('—', $crawler->filter('main')->text(), 'No em-dash in visible copy.');
    }

    public function testItExposesTheCorporateSeoTitleAndDescription(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/pour-les-entreprises');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Relocation d\'Entreprise à Paris', $crawler->filter('title')->text());
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('collaborateurs', $description);
        self::assertLessThanOrEqual(160, mb_strlen($description));
        self::assertSame(1, substr_count($description, ' : '), 'One colon only: the description must read as a sentence.');
        self::assertStringEndsWith('/medias/website/companies-og.webp', (string) $crawler->filter('meta[property="og:image"]')->attr('content'), 'The page shares its own 1200x630 image.');
    }

    public function testItDescribesABusinessServiceWithABreadcrumbInJsonLd(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/pour-les-entreprises');

        self::assertResponseIsSuccessful();
        $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR)['@graph'];
        $nodes = array_column($graph, null, '@type');

        self::assertSame('BusinessAudience', $nodes['Service']['audience']['@type']);
        self::assertSame('Corporate relocation', $nodes['Service']['serviceType']);
        self::assertSame(
            ['Accueil', 'Solutions entreprises'],
            array_column($nodes['BreadcrumbList']['itemListElement'], 'name'),
        );
        self::assertStringEndsWith('/fr/services/pour-les-entreprises', $nodes['BreadcrumbList']['itemListElement'][1]['item']);
    }
}
