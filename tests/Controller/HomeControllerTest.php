<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testItRendersVideoStoriesCarousel(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#video-stories-heading');
        self::assertSelectorCount(8, '[data-controller="video-card"] video');
    }

    public function testItRendersHeroVideoWithSoundAndFullscreenControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="hero-video"] video[autoplay][muted][loop][playsinline][data-action^="click->hero-video#togglePlay"]');
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/webm"]');
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/mp4"]');
        self::assertSelectorExists('[data-controller="hero-video"] button[data-action="hero-video#toggleSound"]');
        self::assertSelectorExists('[data-controller="hero-video"] button[data-action="hero-video#toggleFullscreen"]');
        self::assertSelectorExists('[data-controller="hero-video"][data-playing="true"] button[data-action="hero-video#togglePlay"]');
    }

    public function testItExposesTheHeroVideoAsSchemaOrgVideoObject(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, 512, \JSON_THROW_ON_ERROR)['@graph'];
        $videos = array_values(array_filter($graph, static fn (array $node): bool => 'VideoObject' === $node['@type']));

        self::assertCount(1, $videos);
        self::assertStringEndsWith('/medias/videos/home-hero-poster.webp', $videos[0]['thumbnailUrl']);
        self::assertStringEndsWith('/medias/videos/home-hero-fr.mp4', $videos[0]['contentUrl']);
        self::assertSame('PT1M15S', $videos[0]['duration']);
        self::assertNotEmpty($videos[0]['name']);
        self::assertNotEmpty($videos[0]['description']);
        self::assertNotEmpty($videos[0]['uploadDate']);
    }

    public function testItServesTheHeroVideoInTheCurrentLocale(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/webm"][src$="/medias/videos/home-hero-fr.webm"]');
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/mp4"][src$="/medias/videos/home-hero-fr.mp4"]');

        $crawler = $client->request('GET', '/en');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/webm"][src$="/medias/videos/home-hero-en.webm"]');
        self::assertSelectorExists('[data-controller="hero-video"] source[type="video/mp4"][src$="/medias/videos/home-hero-en.mp4"]');

        $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, 512, \JSON_THROW_ON_ERROR)['@graph'];
        $video = array_values(array_filter($graph, static fn (array $node): bool => 'VideoObject' === $node['@type']))[0];
        self::assertStringEndsWith('/medias/videos/home-hero-en.mp4', $video['contentUrl']);
        self::assertSame('en-GB', $video['inLanguage']);
    }
}
