<?php

namespace App\Tests\CaseStudy\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CaseStudyControllerTest extends WebTestCase
{
    public function testTheFeaturedCaseStudyIsTheBigCardOnTopAndTheOthersAreCards(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ils se sont installés à Paris avec nous');
        // One featured case study, like the featured blog article, and not repeated in the grid
        self::assertSelectorCount(1, '[data-testid="case-study-featured"]');
        self::assertSelectorTextContains('[data-testid="case-study-featured"]', 'À la une');
        self::assertSelectorTextContains('[data-testid="case-study-featured"] h2', 'Une famille londonienne');
        self::assertSelectorExists('a[data-testid="case-study-featured"][href="/fr/etudes-de-cas/famille-londonienne-installee-paris-16"]');
        self::assertSelectorCount(6, '[data-testid="case-study-card"]');
        self::assertSelectorNotExists('[data-testid="case-study-card"][href$="famille-londonienne-installee-paris-16"]');
        // Most recent first
        self::assertSelectorTextContains('[data-testid="case-study-card"] h2', 'Six consultants');
    }

    public function testTheHeaderShowsFiguresComputedFromTheCaseStudies(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas');

        // (18 + 19 + 9 + 14 + 16 + 12 + 15) / 7 = 14.7, 7 different home cities
        self::assertSelectorTextContains('[data-testid="case-study-stats"]', "Délai moyen\u{00A0}: 15 jours");
        self::assertSelectorTextContains('[data-testid="case-study-stats"]', '7 villes de départ');
        self::assertSelectorTextContains('[data-testid="case-study-stats"]', 'Particuliers et entreprises');
    }

    public function testACardShowsTheBadgeAdviserKeyFactsAndLinksToTheCaseStudy(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas');

        $card = $crawler->filter('[data-testid="case-study-card"][href$="meuble-marais-signe-a-distance"]');
        $text = preg_replace('/\s+/u', ' ', $card->text());
        self::assertStringContainsString('Relocation à distance', $text);
        self::assertStringContainsString('Suivi par Eva B.', $text);
        // A case study shows who handled it, not a publication date
        self::assertCount(0, $card->filter('time'));
        // Long titles are cut after two lines, the full title stays available
        self::assertStringContainsString('line-clamp-2', (string) $card->filter('h2')->attr('class'));
        self::assertSame('Un meublé dans le Marais signé depuis New York', $card->filter('h2')->attr('title'));
        self::assertStringContainsString('Jeune actif · Logement meublé', $text);
        self::assertStringContainsString('Bail mobilité de 10 mois', $text);
        // Cover + adviser photo, and one icon per fact (profile, stay, route)
        self::assertCount(2, $card->filter('img'));
        self::assertCount(3, $card->filter('li > svg'));
        self::assertCount(1, $card->filter('img[alt=""]'), 'The adviser photo is decorative: the name is written next to it');
        self::assertSame('De New York à Paris 3e', trim($card->filter('li .sr-only')->text()));
        // The result strip only carries the lease delay
        self::assertSame('Bail signé en 9 jours', trim($card->filter('[data-testid="case-study-result"]')->text()));
        // The whole card leads to the case study
        self::assertSame('/fr/etudes-de-cas/meuble-marais-signe-a-distance', $card->attr('href'));
        self::assertStringNotContainsString("Voir l'étude", $text);
    }

    public function testItServesTheEnglishListing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/case-studies');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'They settled in Paris with us');
        self::assertSelectorTextContains('[data-testid="case-study-featured"]', 'Featured');
        self::assertSelectorTextContains('[data-testid="case-study-featured"] h2', 'A London family');
        self::assertSelectorTextContains('[data-testid="case-study-featured"]', 'Lease signed in 18 days');
    }

    public function testItFiltersBySegment(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas?category=company');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '[data-testid="case-study-card"]');
        self::assertStringNotContainsString('Une famille londonienne', $crawler->filter('turbo-frame#case-study-list')->text());
        self::assertSelectorTextContains('a[aria-current="page"]', 'Entreprises (3)');
    }

    public function testAnUnknownSegmentFallsBackToTheFullList(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas?category=unknown');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(6, '[data-testid="case-study-card"]');
        self::assertSelectorTextContains('a[aria-current="page"]', 'Toutes les études (7)');
    }

    public function testItHasSeoMetadataAndStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas?category=private');

        self::assertSelectorTextContains('title', 'Études de cas');
        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        // Filtered views point to the main listing
        self::assertStringEndsWith('/fr/etudes-de-cas', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSelectorExists('link[rel="alternate"][hreflang="en"][href$="/en/case-studies"]');

        $jsonLd = $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text());
        self::assertStringContainsString('"CollectionPage"', implode('', $jsonLd));
        self::assertStringContainsString('"numberOfItems": 7', implode('', $jsonLd));
    }

    public function testCardImagesAreResponsiveAndLazy(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas');

        $covers = $crawler->filter('[data-testid="case-study-card"] img[srcset]');
        self::assertCount(6, $covers);
        $covers->each(static function ($img): void {
            self::assertSame('lazy', $img->attr('loading'));
            self::assertNotEmpty($img->attr('alt'));
            self::assertStringContainsString('-600.webp 600w', (string) $img->attr('srcset'));
        });
    }

    public function testTheFooterLinksToCaseStudiesRightBelowTheBlog(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/a-propos');

        $links = $crawler->filter('nav[aria-labelledby="footer-menu-2"] a')->each(static fn ($a): string => trim($a->text()));
        $blog = array_search('Blog', $links, true);
        self::assertIsInt($blog);
        self::assertSame('Études de cas', $links[$blog + 1] ?? null);
        self::assertSelectorExists('nav[aria-labelledby="footer-menu-2"] a[href="/fr/etudes-de-cas"]');
    }

    public function testItIsListedInTheHtmlSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/sitemap');

        self::assertSelectorExists('a[href="/fr/etudes-de-cas"]');
    }

    public function testTheDetailPageShowsTheProjectCardAndEveryStoryBlock(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Une famille londonienne installée dans le 16e avant la rentrée');
        self::assertSelectorExists('[data-testid="case-study-banner"][loading="eager"]');

        // Title and summary open the text column; the key facts sit in the side card
        self::assertSelectorExists('article header h1');
        self::assertMatchesRegularExpression('/\\d+ min de lecture/', $crawler->filter('article header')->text());
        $recap = preg_replace('/\s+/u', ' ', $crawler->filter('[data-testid="case-study-recap"]')->text());
        foreach (['Année 2026', 'Client Famille', 'Service Relocation familiale', 'Foyer 4 personnes', '€/mois', 'Logement Logement vide', 'Londres → Paris 16e', 'Bail signé en 18 jours', 'Suivi par Sophie B.'] as $fact) {
            self::assertStringContainsString($fact, $recap);
        }

        self::assertSame(
            // The story in reading order: what the client wanted first, the generic method is gone
            ['Notre méthode', 'La demande', 'Le quartier', 'Pourquoi Paris 16e ?', 'Le dossier locatif', 'Les visites', 'La sécurisation du logement', 'Le projet en chiffres', 'Projet livré', 'Ils nous recommandent'],
            $crawler->filter('article section[aria-labelledby] h2, article section[id] > h2')->each(static fn ($h): string => trim($h->text())),
        );
        // Our generic method stays short
        self::assertSelectorTextContains('section[aria-labelledby="case-study-process"] h2', 'Notre méthode');
        self::assertCount(3, $crawler->filter('section[aria-labelledby="case-study-process"] ol > li'));
        self::assertSelectorNotExists('section[aria-labelledby="case-study-process"] a');

        // The brief is a checklist, readable without opening anything
        self::assertCount(4, $crawler->filter('[data-testid="case-study-criteria"] li'));
        self::assertSelectorTextContains('[data-testid="case-study-criteria"]', 'Proche des écoles et des commerces');
        self::assertSelectorTextContains('[data-testid="case-study-criteria"]', 'Une école à moins de 10 minutes à pied');
        self::assertSelectorNotExists('[data-testid="case-study-criteria"] button');
        self::assertSelectorTextContains('article', 'Employeur');
        self::assertCount(2, $crawler->filter('[data-controller="slider"]'), 'One photo strip per visit');
        self::assertSelectorTextContains('[data-testid="case-study-delivered"]', '18');
        self::assertSelectorTextContains('[data-testid="case-study-delivered"]', 'jours pour trouver et signer le bail');
        self::assertSelectorTextContains('[data-testid="case-study-quote"] blockquote', 'Déménager à Paris avec des enfants');
        // Testimonial boxed like "Notre méthode": title inside the grey frame
        self::assertSelectorExists('section.bg-slate-100[aria-labelledby="case-study-testimonial"] h2#case-study-testimonial');
        // Free text and a table, the same blocks as in blog articles
        self::assertCount(2, $crawler->filter('[data-testid="case-study-text"] p'));
        self::assertSelectorTextContains('[data-testid="case-study-table"] thead', 'Poste');
        self::assertSame(['Loyer', 'Dépôt de garantie', 'Délai'], $crawler->filter('[data-testid="case-study-table"] tbody th[scope="row"]')->each(static fn ($th): string => trim($th->text())));
        self::assertSelectorTextContains('[data-testid="case-study-table"] tbody', '1 mois de loyer');
        self::assertSelectorTextContains('[data-testid="case-study-table"] caption', 'Montants indicatifs');

        // Call to action inside the story, same card as in blog articles
        self::assertSelectorTextContains('[data-testid="case-study-cta"]', 'Vous aussi, installez-vous à Paris sereinement');
        self::assertSelectorExists('[data-testid="case-study-cta"] a[href="/fr/contact"]');
    }

    public function testASingleOccupantIsWrittenInTheSingular(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas/meuble-marais-signe-a-distance');

        self::assertSelectorTextContains('[data-testid="case-study-recap"]', '1 personne');
        self::assertSelectorTextNotContains('[data-testid="case-study-recap"]', '1 personnes');
    }

    public function testItSuggestsThreeOtherCaseStudiesFromTheSameAudienceFirst(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/six-consultants-loges-mission-12-mois');

        $others = $crawler->filter('[data-testid="case-study-card"]');
        self::assertCount(3, $others);
        self::assertSame(0, $others->reduce(static fn ($c): bool => str_contains((string) $c->attr('href'), 'six-consultants'))->count());
        // The two other company cases come first
        self::assertStringContainsString('logement-de-fonction-nouveau-dirigeant', (string) $others->eq(0)->attr('href'));
        self::assertStringContainsString('mutation-directeur-financier-singapour-paris', (string) $others->eq(1)->attr('href'));
        self::assertCount(3, $crawler->filter('[data-testid="case-study-card"] h3'));
    }

    public function testTheDetailPageLinksToItsTranslation(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        self::assertSelectorExists('link[rel="alternate"][hreflang="en"][href$="/en/case-studies/london-family-settled-paris-16"]');
        self::assertSelectorExists('a[href="/en/case-studies/london-family-settled-paris-16"]');
        self::assertStringEndsWith('/fr/etudes-de-cas/famille-londonienne-installee-paris-16', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));

        $jsonLd = implode('', $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('"Article"', $jsonLd);
        self::assertStringContainsString('"BreadcrumbList"', $jsonLd);

        $client->request('GET', '/en/case-studies/london-family-settled-paris-16');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'A London family');
    }

    public function testAnUnknownCaseStudyIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryCaseStudyIsInTheXmlSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.case_studies.xml');

        self::assertResponseIsSuccessful();
        $xml = (string) $client->getResponse()->getContent();
        self::assertSame(14, substr_count($xml, '<loc>'));
        self::assertStringContainsString('/en/case-studies/cfo-transfer-singapore-paris</loc>', $xml);
    }

    public function testEveryCaseStudyHasSearchFriendlyMetadata(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas');
        $urls = $crawler->filter('[data-testid="case-study-card"]')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertNotEmpty($urls);

        foreach (array_merge($urls, ['/en/case-studies/cfo-transfer-singapore-paris']) as $url) {
            $page = $client->request('GET', $url);
            $title = $page->filter('title')->text();
            $description = (string) $page->filter('meta[name="description"]')->attr('content');

            self::assertLessThanOrEqual(60, mb_strlen($title), $url.': title too long for search results ('.$title.')');
            self::assertGreaterThanOrEqual(100, mb_strlen($description), $url.': description too short');
            self::assertLessThanOrEqual(160, mb_strlen($description), $url.': description too long');
            self::assertSame('article', $page->filter('meta[property="og:type"]')->attr('content'), $url);
        }
    }

    public function testOtherPagesKeepTheWebsiteOpenGraphType(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas');

        self::assertSelectorExists('meta[property="og:type"][content="website"]');
    }

    public function testTheServiceLineLinksToTheServicePageOfTheClientsSegment(): void
    {
        $client = static::createClient();

        $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');
        self::assertSelectorExists('[data-testid="case-study-recap"] a[href="/fr/services/trouver-un-logement"]');

        $client->request('GET', '/fr/etudes-de-cas/six-consultants-loges-mission-12-mois');
        self::assertSelectorExists('[data-testid="case-study-recap"] a[href="/fr/services/pour-les-entreprises"]');
    }

    public function testVisitPhotosHaveDistinctAlternativeTexts(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        $alts = $crawler->filter('[data-controller="slider"] img')->each(static fn ($img): string => (string) $img->attr('alt'));
        self::assertCount(8, $alts);
        self::assertSame($alts, array_values(array_unique($alts)));
    }

    public function testTheArticleSchemaDescribesTheCaseStudy(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        $article = null;
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text()) as $json) {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            foreach ($data['@graph'] ?? [$data] as $node) {
                if ('Article' === ($node['@type'] ?? null)) {
                    $article = $node;
                }
            }
        }

        self::assertIsArray($article);
        self::assertSame('Une famille londonienne installée dans le 16e avant la rentrée', $article['headline']);
        self::assertArrayHasKey('dateModified', $article);
        self::assertSame('Études de cas', $article['articleSection']);
        self::assertSame('Paris 16e', $article['about']['name']);
    }

    public function testTheAreaIsAGoogleMapZoneNotAnAddress(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        $map = $crawler->filter('[data-testid="case-study-map"]');
        self::assertCount(1, $map);
        self::assertSame('Carte du quartier recherché, Paris 16e', $map->attr('aria-label'));
        // Loaded lazily, and the area circle is styled before the map draws it
        self::assertStringContainsString('lazy-map', (string) $map->attr('data-controller'));
        self::assertStringContainsString('ux:map:circle:before-create->map-area#style', (string) $map->attr('data-action'));

        $markup = $map->filter('template')->html();
        self::assertStringContainsString('ux-google-map', $markup);
        self::assertStringContainsString('48.8637', $markup);
        // A zone with a badge in its middle, never a pin on the client's address
        $markup = html_entity_decode($markup);
        self::assertStringContainsString('"radius":600', $markup);
        // One marker only: the round area badge in the middle, not the address pin (drop shape)
        self::assertSame(1, substr_count($markup, '"icon":'));
        self::assertStringContainsString('r=\\"18\\"', $markup);
        self::assertStringNotContainsString('M22 0C9.85', $markup);
        self::assertSelectorNotExists('img[src*="map-mock"]');
    }

    public function testASlugFromTheOtherLanguageLeadsToItsTranslation(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/case-studies/famille-londonienne-installee-paris-16');

        self::assertResponseRedirects('/en/case-studies/london-family-settled-paris-16', 301);
    }

    public function testTheHeaderShowsTheShortDescriptionAndTheStoryOpensWithTheSummary(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        self::assertSelectorTextContains('article header', "Quatre chambres près des écoles internationales");
        self::assertStringNotContainsString('En 2026, une famille', $crawler->filter('article header')->text());
        self::assertSelectorTextContains('[data-testid="case-study-summary"]', 'En 2026, une famille de quatre personnes');
    }

    public function testTheKeyFactsShowThePackageWithALinkToPricing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        self::assertSelectorTextContains('[data-testid="case-study-recap"]', 'Formule');
        self::assertSelectorTextContains('[data-testid="case-study-recap"] a[href="/fr/tarifs"]', 'Confié');
    }

    public function testProjectDeliveredShowsTheDelayTheHomesViewedAndTheBudget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/etudes-de-cas/famille-londonienne-installee-paris-16');

        $delivered = preg_replace('/\s+/u', ' ', $crawler->filter('[data-testid="case-study-delivered"]')->text());
        self::assertStringContainsString('18 jours pour trouver et signer le bail', $delivered);
        // Label then figure in the markup (dt/dd), the figure is shown above it
        self::assertStringContainsString('logements visités 5', $delivered);
        self::assertStringContainsString('Budget tenu', $delivered);
        // Amount and "/mois" are separate (the suffix is smaller and may wrap on narrow phones)
        self::assertMatchesRegularExpression('/4\s200\s€\s\/mois/u', $delivered);
    }
}
