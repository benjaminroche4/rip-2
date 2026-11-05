<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ServiceControllerTest extends WebTestCase
{
    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/services/trouver-un-logement');

        self::assertResponseIsSuccessful();
    }

    public function testTheHeroPlaysTheVideoInTheBackgroundBehindTheText(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        $hero = $crawler->filter('[data-controller="background-video"]');
        self::assertCount(1, $hero);
        // Decorative muted loop behind the h1
        self::assertCount(1, $hero->filter('video[autoplay][muted][loop][playsinline][aria-hidden="true"]'));
        self::assertSame(['/medias/videos/bg-relocation-960.webm', '/medias/videos/bg-relocation-960.mp4', '/medias/videos/bg-relocation-1280.webm', '/medias/videos/bg-relocation-1280.mp4'], $hero->filter('video source')->each(static fn ($s): ?string => $s->attr('src')));
        // Lighter file for phones; no controls at all on a background video
        self::assertSame('(max-width: 767px)', $hero->filter('video source')->first()->attr('media'));
        self::assertCount(0, $hero->filter('button, video[controls]'));
        self::assertCount(1, $hero->filter('h1'));
        self::assertStringNotContainsString('Particuliers et entreprises', $hero->text());
        self::assertStringContainsString('Installé en moins de 20 jours', $hero->text());
        self::assertStringContainsString('avis Google', $hero->text());
        // No boxed badges: the call to action is the only filled element
        self::assertCount(0, $hero->filter('div.bg-white'));
        // The poster is the LCP image: preloaded, the old hero image is not
        self::assertCount(1, $crawler->filter('link[rel="preload"][href="/medias/videos/bg-relocation-poster.webp"]'));
        self::assertCount(0, $crawler->filter('link[rel="preload"][href*="rip-find-accomodation"]'));

        // The home page keeps its framed video player
        $home = $client->request('GET', '/');
        self::assertCount(1, $home->filter('[data-controller="hero-video"] video'));
    }

    public function testThePageFollowsTheVisitorReasoningAndShowsThePrices(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        self::assertSelectorTextSame('h1', "Recherche d'appartement à Paris, clé en main");
        // Why (advantages) -> how (process) -> proof (trust) -> price -> FAQ, without the duplicate solutions grid
        self::assertSame([
            'Faites de votre installation à Paris une transition fluide et maîtrisée',
            'Une recherche de logement personnalisée, pensée pour vous',
            'Un accompagnement clair et éprouvé',
            'Votre recherche, notre engagement',
            'Un tarif clair, sans surprise',
            'Tout ce que vous devez savoir',
        ], array_slice($crawler->filter('main h2')->each(static fn ($h): string => trim($h->text())), 0, 6));
        self::assertStringNotContainsString('Nos solutions pour votre logement', $crawler->filter('main')->text());

        $pricing = $crawler->filter('[data-testid="find-accommodation-pricing"]');
        self::assertSame(['Accompagné', 'Confié'], $pricing->filter('h3')->each(static fn ($h): string => trim($h->text())));
        self::assertMatchesRegularExpression('/1\s?490\s?€/u', $pricing->text());
        self::assertMatchesRegularExpression('/2\s?190\s?€/u', $pricing->text());
        self::assertSame('/fr/tarifs', $pricing->filter('a')->last()->attr('href'));

        // FAQ answers link to the pricing and callback pages (internal linking)
        self::assertCount(1, $crawler->filter('#accordion-1 a[href="/fr/tarifs"]'));
        self::assertCount(1, $crawler->filter('#accordion-1 a[href="/fr/on-vous-rappelle"]'));
        self::assertStringContainsString('+900 installations', (string) $crawler->filter('meta[name="description"]')->attr('content'));
    }

    public function testSeoAndImageWeightsAreTakenCareOf(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        // Title aligned with the h1 query, no stray capital
        self::assertSame("Relocation In Paris - Recherche d'appartement à Paris", trim($crawler->filter('title')->text()));
        // Both packages with their price in the Service structured data
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"price": "1490"', $html);
        self::assertStringContainsString('"price": "2190"', $html);
        // Light versions for phones, small avatar instead of the full team photo
        self::assertStringContainsString('rip-card-1-640.webp 640w', $html);
        self::assertStringNotContainsString('/medias/team/team_2.webp', $html);
    }

    public function testWordingIsConsistentWithTheRestOfTheSite(): void
    {
        $client = static::createClient();
        foreach (['/', '/fr/services/trouver-un-logement', '/fr/a-propos', '/fr/faq', '/fr/tarifs'] as $page) {
            $client->request('GET', $page);
            $text = (string) $client->getResponse()->getContent();
            // One delay promise (under 20 days), one word for the count (installations), one spelling of off-market
            self::assertStringNotContainsString('Paris en 4 semaines', $text, $page);
            self::assertStringNotContainsString('relocations réussies', $text, $page);
            self::assertStringNotContainsString('Off market', $text, $page);
            // Languages: the team speaks French, English, Spanish and German (About page flags)
            self::assertDoesNotMatchRegularExpression('/en français (et|ou) en anglais/u', $text, $page);
        }
    }

    public function testTheHomeSearchFaqAnswersTheDecisionBlockers(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        self::assertResponseIsSuccessful();
        // Questions mixed from several namespaces (remote, timing, profile, budget, fees, risk)
        self::assertSame([
            'Puis-je effectuer tout le processus à distance (depuis l’étranger) ?',
            'Combien de temps faut-il pour trouver un logement à Paris ?',
            "J'ai un profil atypique, pouvez-vous m'aider ?",
            'Mon loyer est-il suffisant pour trouver un bon appartement à Paris ?',
            'Est-ce que vos honoraires sont inclus dans le loyer ?',
            'Que se passe-t-il si vous ne trouvez pas de logement ?',
        ], $crawler->filter('#accordion-1 h3')->each(static fn ($h): string => trim($h->text())));
        // Searches start from a 1,000 euros monthly rent
        self::assertStringContainsString("à partir de 1\u{00A0}000\u{00A0}€ de loyer par mois", $crawler->filter('#accordion-1')->text());
        self::assertStringNotContainsString('.title', $crawler->filter('#accordion-1')->text());
    }

    public function testTheTrustBlockGivesProofsWithoutRepeatingTheProcess(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/services/trouver-un-logement');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Depuis 2015, nos agents immobiliers parisiens ont accompagné', $html);
        self::assertStringContainsString('<strong class="font-semibold text-neutral-800">plus de 900 installations à Paris</strong>', $html);
        self::assertStringNotContainsString('rigueur et bienveillance', $html);
    }

    public function testTheAdvantageCardsEachGiveADistinctBenefit(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        foreach (['Gagnez des semaines de recherche', 'Un dossier que les propriétaires acceptent', 'Des biens avant tout le monde', 'Un expert qui parle votre langue'] as $title) {
            self::assertSelectorTextContains('body', $title);
        }
        // Rating line: the Google logo stands for the "G", screen readers still read "Google"
        $reviews = $crawler->filter('p:contains("avis")')->reduce(static fn ($p): bool => str_contains($p->html(), 'oogle</span>'));
        self::assertCount(1, $reviews);
        self::assertStringContainsString('Sur +', $reviews->text());
        self::assertSame('Google', $reviews->filter('.sr-only')->text());
    }

    public function testTheProcessStepsSpeakToTheVisitor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/trouver-un-logement');

        $steps = $crawler->filter('[data-process-stepper-target="card"] h3')->each(static fn ($h): string => trim($h->text()));
        self::assertSame([
            'Un premier appel pour faire connaissance',
            'Votre recherche, cadrée ensemble',
            'Nous cherchons pour vous',
            'Vous visitez, sur place ou en vidéo',
            'Un dossier prêt avant les autres',
            'Vous signez en confiance',
            'Bienvenue chez vous',
        ], $steps);
        self::assertStringNotContainsString('intra/extra', $crawler->filter('[data-controller="process-stepper"]')->text());
    }
}
