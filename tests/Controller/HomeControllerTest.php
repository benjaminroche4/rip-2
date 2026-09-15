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
        self::assertSelectorCount(6, '[data-controller="video-card"] video');
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
}
