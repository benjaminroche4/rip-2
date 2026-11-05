<?php

namespace App\Tests\CaseStudy\Domain;

use App\CaseStudy\Domain\CaseStudyDocument;
use App\CaseStudy\Domain\CaseStudyMapper;
use App\CaseStudy\Domain\CaseStudyOffer;
use App\CaseStudy\Domain\CaseStudySegment;
use PHPUnit\Framework\TestCase;

final class CaseStudyMapperTest extends TestCase
{
    /**
     * A row as returned by SanityCaseStudyRepository::FIELDS.
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function row(array $override = []): array
    {
        return array_replace([
            'title' => 'Une famille londonienne installée dans le 16e',
            'seoTitle' => 'Famille de Londres relogée à Paris 16e',
            'shortDescription' => 'Quatre chambres près des écoles internationales.',
            'slug' => 'famille-londonienne-paris-16',
            'segment' => 'private',
            'relocationType' => 'Relocation familiale',
            'mainPhoto' => ['url' => 'https://cdn.sanity.io/images/p/d/cover.jpg', 'alt' => 'Salon lumineux', 'hotspot' => null, 'crop' => null, 'dims' => ['width' => 2000, 'height' => 1000]],
            'featured' => true,
            'offer' => 'confie',
            'homesVisited' => 5,
            'publishedAt' => '2026-10-21',
            'agentName' => 'Sophie B.',
            'agentPhoto' => ['url' => 'https://cdn.sanity.io/images/p/d/sophie.jpg'],
            'clientProfile' => 'Famille',
            'householdSize' => 4,
            'budget' => 4200,
            'housingType' => 'Logement vide',
            'stayType' => 'Installation longue durée',
            'origin' => 'Londres',
            'district' => 'Paris 16e',
            'daysToSettle' => 18,
            'location' => ['_type' => 'geopoint', 'lat' => 48.8637, 'lng' => 2.2769],
            'summary' => 'En 2026, une famille de quatre personnes…',
            'process' => [['title' => 'Cadrer le projet', 'text' => 'Besoins et budget.'], ['title' => '', 'text' => 'ignored']],
            'criteria' => [['title' => 'Proche des écoles', 'text' => 'Moins de 10 minutes à pied.'], ['title' => '', 'text' => 'ignored']],
            'locationText' => 'Nous avons ciblé Paris 16e.',
            'textBlock' => ['title' => 'Pourquoi Paris 16e ?', 'content' => [
                ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Premier '], ['_type' => 'span', 'text' => 'paragraphe.']]],
                ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '   ']]],
                ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Second paragraphe.']]],
            ]],
            'applicationText' => 'Nous préparons le dossier.',
            'applicationDocuments' => ['employer', 'unknown', 'income', 'employer'],
            'visits' => [['title' => 'Première visite', 'text' => 'Sélection.', 'photos' => [['url' => 'https://cdn.sanity.io/images/p/d/v1.jpg', 'alt' => 'Rue'], ['alt' => 'no asset']]]],
            'securingText' => 'Nous sécurisons.',
            'figures' => ['title' => 'Le projet en chiffres', 'caption' => 'Indicatif.', 'table' => ['rows' => [
                ['cells' => ['Poste', 'Détail']],
                ['cells' => ['Loyer', '4 200 €/mois']],
                ['cells' => ['', '']],
            ]]],
            'quote' => 'Tout était prêt.',
            'quoteAuthor' => 'Emma, venue de Londres',
            'quotePhoto' => null,
            'alternateSlug' => 'london-family-paris-16',
        ], $override);
    }

    public function testItMapsEveryFieldTheDetailPageNeeds(): void
    {
        $caseStudy = (new CaseStudyMapper())->fromGroqArray(self::row());

        self::assertNotNull($caseStudy);
        self::assertSame(CaseStudySegment::Private, $caseStudy->segment);
        self::assertTrue($caseStudy->featured);
        self::assertSame(CaseStudyOffer::Confie, $caseStudy->offer);
        self::assertSame(5, $caseStudy->homesVisited);
        self::assertSame('Salon lumineux', $caseStudy->photoAlt);
        self::assertCount(1, $caseStudy->visits[0]['photos'], 'A photo without asset is skipped');
        self::assertSame('Famille de Londres relogée à Paris 16e', $caseStudy->seoTitle);
        self::assertStringStartsWith('https://cdn.sanity.io/images/p/d/cover.jpg?w=1200&h=800', $caseStudy->photo);
        self::assertStringStartsWith('https://cdn.sanity.io/images/p/d/cover.jpg?w=600&h=400', $caseStudy->photoSmall);
        self::assertStringStartsWith('https://cdn.sanity.io/images/p/d/sophie.jpg?w=96', $caseStudy->agentPhoto);
        self::assertSame(48.8637, $caseStudy->latitude);
        self::assertSame('london-family-paris-16', $caseStudy->alternateSlug);
        self::assertSame('2026-10-21', $caseStudy->publishedAt->format('Y-m-d'));
        self::assertSame([['title' => 'Cadrer le projet', 'text' => 'Besoins et budget.']], $caseStudy->process);
        // Empty criteria are dropped, unknown and repeated documents too
        self::assertCount(1, $caseStudy->criteria);
        self::assertSame([CaseStudyDocument::Employer, CaseStudyDocument::Income], $caseStudy->applicationDocuments);
        self::assertStringStartsWith('https://cdn.sanity.io/images/p/d/v1.jpg?w=450&h=600', $caseStudy->visits[0]['photos'][0]['src']);
        // Portable Text becomes plain paragraphs, blank ones skipped
        self::assertSame(['Premier paragraphe.', 'Second paragraphe.'], $caseStudy->textBlock['paragraphs'] ?? null);
        // Empty table rows are skipped
        self::assertSame([['Poste', 'Détail'], ['Loyer', '4 200 €/mois']], $caseStudy->table['rows'] ?? null);
        // No testimonial photo: the cover stands in
        self::assertStringStartsWith('https://cdn.sanity.io/images/p/d/cover.jpg?w=120&h=120', $caseStudy->quotePhoto);
    }

    public function testOptionalBlocksAndTranslationMayBeMissing(): void
    {
        $caseStudy = (new CaseStudyMapper())->fromGroqArray(self::row(['textBlock' => null, 'figures' => null, 'alternateSlug' => null]));

        self::assertNotNull($caseStudy);
        self::assertNull($caseStudy->textBlock);
        self::assertNull($caseStudy->table);
        self::assertNull($caseStudy->alternateSlug);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteDocuments(): iterable
    {
        yield 'no slug' => [['slug' => null]];
        yield 'unknown segment' => [['segment' => 'other']];
        yield 'no cover' => [['mainPhoto' => null]];
        yield 'cover without asset' => [['mainPhoto' => ['alt' => 'x']]];
        yield 'no adviser' => [['agentName' => null]];
        yield 'no location' => [['location' => null]];
        yield 'no date' => [['publishedAt' => 'not-a-date']];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteDocuments')]
    public function testAnIncompleteDocumentIsSkippedRatherThanBreakingThePage(array $override): void
    {
        self::assertNull((new CaseStudyMapper())->fromGroqArray(self::row($override)));
    }

    public function testImagesAreCroppedAroundTheEditorsFocalPoint(): void
    {
        $row = self::row(['mainPhoto' => [
            'url' => 'https://cdn.sanity.io/images/p/d/cover.jpg',
            'alt' => 'Salon',
            // Editor kept the right half (left crop 0.5) and put the hotspot at 3/4 of the width
            'crop' => ['left' => 0.5, 'right' => 0.0, 'top' => 0.0, 'bottom' => 0.0],
            'hotspot' => ['x' => 0.75, 'y' => 0.25, 'width' => 0.2, 'height' => 0.2],
            'dims' => ['width' => 2000, 'height' => 1000],
        ]]);

        $url = (string) (new CaseStudyMapper())->fromGroqArray($row)?->photo;
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame('1000,0,1000,1000', $query['rect']);
        self::assertSame('focalpoint', $query['crop']);
        // 0.75 of the whole image = 0.5 of the kept right half
        self::assertSame('0.5', $query['fp-x']);
        self::assertSame('0.25', $query['fp-y']);
        self::assertSame(['1200', '800'], [$query['w'], $query['h']]);
    }

    public function testWithoutHotspotOrCropTheImageIsSimplyResized(): void
    {
        $url = (string) (new CaseStudyMapper())->fromGroqArray(self::row())?->photo;

        self::assertStringNotContainsString('fp-x', $url);
        self::assertStringNotContainsString('rect', $url);
        self::assertStringContainsString('w=1200&h=800&fit=crop', $url);
    }
}
