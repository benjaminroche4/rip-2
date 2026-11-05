<?php

namespace App\Tests\CaseStudy\Domain;

use App\CaseStudy\Domain\CaseStudyDocument;
use App\CaseStudy\Domain\CaseStudyMapper;
use App\CaseStudy\Domain\CaseStudyOffer;
use App\CaseStudy\Domain\CaseStudySegment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class CaseStudyMapperTest extends TestCase
{
    /** Identity translator: translated values come back as their translation key. */
    private static function mapper(): CaseStudyMapper
    {
        return new CaseStudyMapper(new IdentityTranslator());
    }

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
            'clientProfile' => 'family',
            'householdSize' => 4,
            'budget' => 4200,
            'housingType' => 'unfurnished',
            'stayType' => 'Installation longue durée',
            'origin' => 'Londres',
            'district' => 16,
            'daysToSettle' => 18,
            'location' => ['_type' => 'geopoint', 'lat' => 48.8637, 'lng' => 2.2769],
            'summary' => 'En 2026, une famille de quatre personnes…',
            'process' => [['title' => 'Cadrer le projet', 'text' => 'Besoins et budget.'], ['title' => '', 'text' => 'ignored']],
            'criteria' => [['title' => 'Proche des écoles', 'text' => 'Moins de 10 minutes à pied.'], ['title' => '', 'text' => 'ignored']],
            'locationText' => 'Nous avons ciblé Paris 16e.',
            'body' => [
                ['_type' => 'wysiwygBlock', '_key' => 'w1', 'title' => 'Pourquoi Paris 16e ?', 'content' => [
                    ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Premier paragraphe.']]],
                ]],
                ['_type' => 'tableBlock', '_key' => 't1', 'title' => 'Chiffres'],
                ['_type' => 'unknownBlock', '_key' => 'x1'],
            ],
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
            'quoteAuthor' => 'Emma',
            'alternateSlug' => 'london-family-paris-16',
        ], $override);
    }

    public function testItMapsEveryFieldTheDetailPageNeeds(): void
    {
        $caseStudy = self::mapper()->fromGroqArray(self::row());

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
        // Blocks the site renders are kept as Sanity returns them, unknown types are dropped
        self::assertSame(['wysiwygBlock', 'tableBlock'], array_column($caseStudy->body, '_type'));
        // Values picked from Studio lists are translated (identity translator: the key comes back)
        self::assertSame('caseStudy.profile.family', $caseStudy->clientProfile);
        self::assertSame('caseStudy.housing.unfurnished', $caseStudy->housingType);
        self::assertSame('Paris 16e', $caseStudy->district);
        // Empty table rows are skipped
        self::assertSame([['Poste', 'Détail'], ['Loyer', '4 200 €/mois']], $caseStudy->table['rows'] ?? null);
    }

    public function testOptionalBlocksAndTranslationMayBeMissing(): void
    {
        $caseStudy = self::mapper()->fromGroqArray(self::row(['body' => null, 'figures' => null, 'alternateSlug' => null]));

        self::assertNotNull($caseStudy);
        self::assertSame([], $caseStudy->body);
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
        yield 'no delay' => [['daysToSettle' => null]];
        yield 'no relocation type' => [['relocationType' => '']];
        yield 'no SEO title' => [['seoTitle' => null]];
        yield 'no arrondissement' => [['district' => null]];
        yield 'no date' => [['publishedAt' => 'not-a-date']];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteDocuments')]
    public function testAnIncompleteDocumentIsSkippedRatherThanBreakingThePage(array $override): void
    {
        self::assertNull(self::mapper()->fromGroqArray(self::row($override)));
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

        $url = (string) self::mapper()->fromGroqArray($row)?->photo;
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
        $url = (string) self::mapper()->fromGroqArray(self::row())?->photo;

        self::assertStringNotContainsString('fp-x', $url);
        self::assertStringNotContainsString('rect', $url);
        self::assertStringContainsString('w=1200&h=800&fit=crop', $url);
    }

    public function testTheArrondissementIsWrittenTheWayEachLanguageSaysIt(): void
    {
        self::assertSame('Paris 1er', self::mapper()->fromGroqArray(self::row(['district' => 1]), 'fr')?->district);
        self::assertSame('Paris 16e', self::mapper()->fromGroqArray(self::row(['district' => 16]), 'fr')?->district);
        self::assertSame('Paris 1st', self::mapper()->fromGroqArray(self::row(['district' => 1]), 'en')?->district);
        self::assertSame('Paris 16th', self::mapper()->fromGroqArray(self::row(['district' => 16]), 'en')?->district);
        // A document written before the select keeps its text; out of range or missing is skipped
        self::assertSame('Paris 16th', self::mapper()->fromGroqArray(self::row(['district' => 'Paris 16th']), 'en')?->district);
        self::assertNull(self::mapper()->fromGroqArray(self::row(['district' => 21])));
    }

    public function testAFreeTextProfileFromBeforeTheListIsKeptAsWritten(): void
    {
        $caseStudy = self::mapper()->fromGroqArray(self::row(['clientProfile' => 'Famille', 'housingType' => 'Logement vide']));

        self::assertNotNull($caseStudy);
        self::assertSame('Famille', $caseStudy->clientProfile);
        self::assertSame('Logement vide', $caseStudy->housingType);
    }

    public function testEveryProfileOfTheStudioListIsTranslated(): void
    {
        foreach (['family', 'couple', 'youngProfessional', 'student', 'executive', 'employee', 'entrepreneur', 'atypical', 'company'] as $key) {
            self::assertSame('caseStudy.profile.'.$key, self::mapper()->fromGroqArray(self::row(['clientProfile' => $key]))?->clientProfile, $key);
        }
    }

    public function testWithoutAPointOnTheMapTheAreaGoesInsideTheArrondissement(): void
    {
        $fr = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 16, 'slug' => 'famille-paris-16']), 'fr');
        $en = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 16, 'slug' => 'family-paris-16']), 'en');
        self::assertNotNull($fr);
        self::assertNotNull($en);

        // Near the 16th centre (48.8603, 2.2756), at most about 400 m away
        self::assertEqualsWithDelta(48.8603, $fr->latitude, 0.0036);
        self::assertEqualsWithDelta(2.2756, $fr->longitude, 0.0051);
        // The FR and EN versions show the same spot, and it does not change from one visit to the next
        self::assertSame([$fr->latitude, $fr->longitude], [$en->latitude, $en->longitude]);
        $again = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 16]), 'fr');
        self::assertSame([$fr->latitude, $fr->longitude], [$again?->latitude, $again?->longitude]);
    }

    public function testTwoCaseStudiesOfOneArrondissementDoNotStackUp(): void
    {
        $one = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 11, 'daysToSettle' => 9]));
        $two = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 11, 'daysToSettle' => 14]));

        self::assertNotSame([$one?->latitude, $one?->longitude], [$two?->latitude, $two?->longitude]);
    }

    public function testAPointSetByTheEditorIsKept(): void
    {
        $caseStudy = self::mapper()->fromGroqArray(self::row(['location' => ['lat' => 48.85, 'lng' => 2.29], 'district' => 16]));

        self::assertSame([48.85, 2.29], [$caseStudy?->latitude, $caseStudy?->longitude]);
    }

    public function testAnOlderWrittenDistrictStillFindsItsArrondissement(): void
    {
        $caseStudy = self::mapper()->fromGroqArray(self::row(['location' => null, 'district' => 'Paris 16th']), 'en');

        self::assertNotNull($caseStudy);
        self::assertSame('Paris 16th', $caseStudy->district);
        self::assertEqualsWithDelta(48.8603, $caseStudy->latitude, 0.0036);
    }

    public function testADocumentWithOnlyTheRequiredFieldsIsMapped(): void
    {
        $minimal = [
            'title' => 'Une famille installée dans le 16e',
            'seoTitle' => 'Famille installée à Paris 16e',
            'shortDescription' => 'Quatre chambres près des écoles.',
            'slug' => 'famille-paris-16',
            'segment' => 'private',
            'relocationType' => 'Relocation familiale',
            'mainPhoto' => ['url' => 'https://cdn.sanity.io/images/p/d/cover.jpg', 'alt' => 'Salon'],
            'publishedAt' => '2026-10-21',
            'district' => 16,
            'daysToSettle' => 18,
        ];

        $caseStudy = self::mapper()->fromGroqArray($minimal);

        self::assertNotNull($caseStudy);
        self::assertNull($caseStudy->agentName);
        self::assertNull($caseStudy->agentPhoto);
        self::assertNull($caseStudy->householdSize);
        self::assertNull($caseStudy->budget);
        self::assertNull($caseStudy->offer);
        self::assertNull($caseStudy->homesVisited);
        self::assertNull($caseStudy->table);
        self::assertNull($caseStudy->alternateSlug);
        self::assertFalse($caseStudy->featured);
        foreach (['clientProfile', 'housingType', 'stayType', 'origin', 'summary', 'locationText', 'applicationText', 'securingText', 'quote', 'quoteAuthor'] as $text) {
            self::assertSame('', $caseStudy->{$text}, $text);
        }
        foreach (['criteria', 'applicationDocuments', 'visits', 'body', 'process'] as $list) {
            self::assertSame([], $caseStudy->{$list}, $list);
        }
        // The map still has its area, inside the 16th
        self::assertEqualsWithDelta(48.8603, $caseStudy->latitude, 0.0036);
    }
}
