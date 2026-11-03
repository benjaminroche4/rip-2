<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Controller;

use App\Marketing\Service\FaqCatalog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class FaqControllerTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private static function faqPageNode(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            foreach ($data['@graph'] ?? [$data] as $node) {
                if ('FAQPage' === ($node['@type'] ?? null)) {
                    return $node;
                }
            }
        }
        self::fail('No FAQPage JSON-LD node');
    }

    public function testItListsEveryTopicWithItsQuestionsAndATopicCard(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        $categories = static::getContainer()->get(FaqCatalog::class)->categories();
        $total = array_sum(array_map(static fn ($c): int => count($c->entries), $categories));

        foreach ($categories as $category) {
            // One section per topic, reachable from the card with its question count
            self::assertSelectorExists(sprintf('section#%s h2', $category->slug));
            $link = $crawler->filter(sprintf('nav[data-controller="toc"] a[href="#%s"]', $category->slug));
            self::assertCount(1, $link);
            self::assertStringContainsString(sprintf('%02d', count($category->entries)), $link->text());
            self::assertCount(count($category->entries), $crawler->filter(sprintf('section#%s [data-slot="accordion-item"]', $category->slug)));
        }

        self::assertSelectorTextContains('section#relocation h2', 'La relocation à Paris');
        self::assertSelectorTextContains('section#property-management', 'Gérez-vous les biens en courte, moyenne et longue durée ?');
        self::assertSelectorNotExists('section#find-a-tenant');
        // Closed answers stay in the HTML for crawlers
        self::assertCount($total, $crawler->filter('[data-slot="accordion-content"]'));
        self::assertSelectorExists('aside a[href="/fr/contact"]');
    }

    public function testItExposesEveryQuestionInTheFaqPageSchema(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        $categories = static::getContainer()->get(FaqCatalog::class)->categories();
        $total = array_sum(array_map(static fn ($c): int => count($c->entries), $categories));

        $questions = self::faqPageNode($crawler)['mainEntity'];
        self::assertCount($total, $questions);
        foreach ($questions as $question) {
            self::assertNotSame('', $question['name']);
            self::assertNotSame('', $question['acceptedAnswer']['text']);
            // A missing translation would surface as its raw key
            self::assertDoesNotMatchRegularExpression('/^[a-zA-Z]+(\.[a-zA-Z0-9]+)+$/', $question['name']);
        }
        self::assertContains('What is the difference between the Guided and Entrusted plans?', array_column($questions, 'name'));
    }

    public function testItTargetsRelocationKeywordsInTheHeadingAndTitle(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Questions fréquentes sur la relocation à Paris');
        self::assertSelectorTextSame('title', 'Relocation In Paris - FAQ : relocation et logement à Paris');
    }

    public function testAccordionQuestionsAreNotNestedHeadings(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        // Regression: the trigger used to render <h3><button><h3>question</h3></button></h3>
        self::assertCount(0, $crawler->filter('h3 h3, button h1, button h2, button h3'));
        self::assertGreaterThan(30, $crawler->filter('h3 > button[data-slot="accordion-trigger"]')->count());
    }

    public function testAnswersLinkToOtherPagesInContext(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        $answerLinks = $crawler->filter('[data-slot="accordion-content"] a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        foreach (['/fr/tarifs', '/fr/contact', '/fr/nos-biens', '/fr/services/trouver-un-logement', '/fr/services/gestion-locative-paris', '/fr/blog'] as $path) {
            self::assertContains($path, $answerLinks);
        }
        // Every placeholder is resolved: no raw "%xxxUrl%" left in a link
        foreach ($answerLinks as $href) {
            self::assertStringStartsWith('/fr/', $href);
        }
        // Links live in the answers, not next to the topic headings
        self::assertCount(0, $crawler->filter('section[id] > div > h2 + a, section[id] > h2 + a'));
    }

    public function testSharedAnswersKeepTheirLinksOnOtherPages(): void
    {
        $client = static::createClient();

        foreach (['/', '/fr/tarifs', '/fr/services/trouver-un-logement', '/fr/services/pour-les-entreprises', '/fr/services/gestion-locative-paris'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertStringNotContainsString('Url%', (string) $client->getResponse()->getContent(), $path);
        }

        // "What is a relocation service?" (home) links to the find-accommodation service
        $crawler = $client->request('GET', '/');
        self::assertGreaterThan(0, $crawler->filter('[data-slot="accordion-content"] a[href="/fr/services/trouver-un-logement"]')->count());
    }

    public function testItDeclaresTheBreadcrumb(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        $breadcrumb = null;
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            foreach (json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR)['@graph'] ?? [] as $node) {
                if ('BreadcrumbList' === ($node['@type'] ?? null)) {
                    $breadcrumb = $node;
                }
            }
        }
        self::assertNotNull($breadcrumb);
        self::assertSame(['Accueil', 'FAQ'], array_column($breadcrumb['itemListElement'], 'name'));
    }

    public function testFaqQuestionsAreMarkedUpOnlyOnTheFaqPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/services/gestion-locative-paris');

        self::assertResponseIsSuccessful();
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            self::assertNotContains('FAQPage', array_column($data['@graph'] ?? [$data], '@type'));
        }
        // The questions themselves stay visible on the service page
        self::assertSelectorTextContains('main', 'Gérez-vous les biens en courte, moyenne et longue durée ?');
    }

    public function testPropertyManagementTopicMentionsThePartnerTheKonciergerie(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section#property-management', 'Une gestion locative en partenariat avec TheKonciergerie');
        $link = $crawler->filter('section#property-management a[href="https://thekonciergerie.com/"]');
        self::assertCount(1, $link);
        self::assertSame('noopener noreferrer', $link->attr('rel'));
        self::assertSame('_blank', $link->attr('target'));
        // Only that topic carries the partner card
        self::assertCount(1, $crawler->filter('a[href="https://thekonciergerie.com/"]'));
    }

    public function testKeyFactsAreEmphasizedInAnswersButNotInTheSchema(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/faq');

        self::assertResponseIsSuccessful();
        $strong = $crawler->filter('[data-slot="accordion-content"] strong')->each(static fn (Crawler $s): string => $s->text());
        self::assertContains('moins de 20 jours', $strong);
        self::assertContains('1 490 €', $strong);
        // Emphasis stays selective: a minority of the answers
        self::assertLessThan(40, count($strong));

        foreach (self::faqPageNode($crawler)['mainEntity'] as $question) {
            self::assertStringNotContainsString('<strong>', $question['acceptedAnswer']['text']);
        }
    }
}
