<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AboutUsControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/a-propos');

        self::assertResponseIsSuccessful();
    }

    public function testEveryAgentCardShowsRoleLanguagesAndBio(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        $cards = $crawler->filter('[data-testid="agent-card"]');
        self::assertCount(8, $cards);

        $julien = $cards->eq(0);
        self::assertSame('Julien L.', $julien->filter('h3')->text());
        self::assertSame('Julien L., Consultant logement', $julien->filter('img')->attr('alt'));
        self::assertSame(['Français', 'Anglais'], $julien->filter('ul[aria-label="Langues parlées"] .sr-only')->each(static fn ($l): string => $l->text()));
        self::assertStringContainsString('Spécialiste des locations très demandées à Paris.', $julien->text());
        // Light 640px versions, not the 1024px originals
        self::assertSame('/medias/team/card/team_1.webp', $julien->filter('img')->attr('src'));
        // Only the first row is loaded eagerly
        self::assertSame(['eager', 'eager', 'eager', 'lazy', 'lazy', 'lazy', 'lazy', 'lazy'], $cards->filter('img')->each(static fn ($img): ?string => $img->attr('loading')));
        // Charles is a consultant, not a "consultante"
        self::assertStringContainsString('Consultant mobilité internationale', $cards->eq(2)->text());
    }

    public function testTheMissionBlockShowsTheRatingAndKeyFiguresUnderTheHeader(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        $mission = $crawler->filter('[data-testid="about-mission"]');
        self::assertCount(1, $mission);
        // Right after the page header (h1), before the team
        self::assertCount(1, $crawler->filter('section:has(h1) + section[data-testid="about-mission"]'));
        self::assertStringContainsString('Trouver votre logement à Paris, l\'esprit tranquille.', $mission->filter('h2')->text());
        self::assertSame(['+900', '+25', '+80'], $mission->filter('dd')->each(static fn ($dd): string => trim($dd->text())));
        self::assertSame(['Installations réussies', "Années d'expertise cumulées", 'Partenaires immobiliers à Paris'], $mission->filter('dt')->each(static fn ($dt): string => trim($dt->text())));
        self::assertSelectorExists('[data-testid="about-mission"] a[href^="https://share.google/"][target="_blank"][rel~="noopener"]');
        self::assertStringNotContainsString('—', $mission->text());
    }

    public function testTheStoryShowsThePhotoAndAShortJourneyInsteadOfTheOldGallery(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        $story = $crawler->filter('[data-testid="about-story"]');
        self::assertCount(1, $story);
        self::assertSame('Née d\'une passion pour Paris', $story->filter('h2')->text());
        self::assertSame('/medias/website/paris_2_story.webp', $story->filter('img[alt]')->first()->attr('src'));
        $years = $story->filter('ol > li > div:first-child > p')->each(static fn ($p): string => trim($p->text()));
        // First and last dates come from the shared globals: founding year and the award year shown on the badges
        $awardYear = (string) static::getContainer()->get('twig')->getGlobals()['site_award_year'];
        self::assertSame(['2015', '2023', '2024', $awardYear], $years);
        self::assertStringContainsString('Collectif d\'agents immobiliers indépendants réunis à Paris depuis 2015, nous avons fondé Relocation in Paris, agence de relocation à Paris, en 2023', $story->text());
        self::assertCount(4, $story->filter('ol > li'));
        // Trust badges live in the mission block only
        self::assertCount(0, $story->filter('a[href^="https://share.google/"]'));
        // The 4-photo gallery is gone
        self::assertCount(0, $crawler->filter('img[src*="medias/website/paris_1"], img[src*="medias/website/paris_3"], img[src*="medias/website/paris_4"]'));
        self::assertStringNotContainsString('—', $story->text());
        // Key phrases in bold for SEO
        self::assertSame(['Relocation in Paris, agence de relocation à Paris', 'expatriés, familles et entreprises'], $story->filter('p strong')->each(static fn ($b): string => $b->text()));
        // Footer menus are named landmarks (each nav labelled by its column title)
        self::assertCount(4, $crawler->filter('footer nav[aria-labelledby^="footer-menu-"]'));
    }

    public function testTheHeaderPresentsTheTeamWithThreePhotos(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        self::assertSelectorTextSame('h1', 'Votre agence de relocation à Paris');
        $photos = $crawler->filter('[data-testid="about-hero-photos"] img');
        self::assertCount(3, $photos);
        $photos->each(static function ($img): void {
            self::assertNotEmpty($img->attr('alt'));
            self::assertNotEmpty($img->attr('width'));
            self::assertNotEmpty($img->attr('height'));
        });
        // Lighter versions for phones (photo 3 is already small)
        self::assertStringContainsString('about_hero_2-340.webp 340w', (string) $photos->eq(1)->attr('srcset'));
        self::assertStringContainsString('paris_2_story-640.webp 640w', (string) $crawler->filter('[data-testid="about-story"] img')->attr('srcset'));
        self::assertStringNotContainsString('—', $crawler->filter('section:has(h1)')->text());
    }

    public function testDatesAreConsistentInTheMetaAndTheStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        self::assertStringContainsString('fondée en 2023', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringContainsString('"foundingDate": "2023"', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('%year%', (string) $client->getResponse()->getContent());
    }

    public function testTheRelocationCountIsTheSameOnEveryPage(): void
    {
        $client = static::createClient();
        foreach (['/', '/fr/services/trouver-un-logement', '/fr/a-propos'] as $page) {
            $client->request('GET', $page);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('+900', $html, $page);
            self::assertStringNotContainsString('+350', $html, $page);
        }
    }

    public function testServicePagesUseTheSameFoundingYear(): void
    {
        $client = static::createClient();
        foreach (['/fr/services/trouver-un-logement', '/fr/services/gestion-locative-paris'] as $page) {
            $client->request('GET', $page);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Depuis 2015, notre équipe', $html, $page);
            self::assertStringNotContainsString('dix ans', $html, $page);
            self::assertStringNotContainsString('%year%', $html, $page);
        }
    }

    public function testTheGoogleRatingIsTheSameOnEveryPageAndInTheStructuredData(): void
    {
        $client = static::createClient();
        foreach (['/', '/fr/a-propos', '/fr/on-vous-rappelle', '/fr/services/trouver-un-logement', '/fr/services/gestion-locative-paris'] as $page) {
            $client->request('GET', $page);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('"ratingValue": "5.0"', $html, $page);
            self::assertDoesNotMatchRegularExpression('/>\s*4[.,]9(?:\/5)?\s*</', $html, $page);
        }
    }

    public function testAgentCardsAreTranslatedInEnglish(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/about-us');

        self::assertResponseIsSuccessful();
        $julien = $crawler->filter('[data-testid="agent-card"]')->eq(0);
        self::assertStringContainsString('Housing Consultant', $julien->text());
        self::assertStringContainsString('Specialized in securing the most competitive rentals in Paris.', $julien->text());
    }
}
