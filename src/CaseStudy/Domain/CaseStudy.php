<?php

namespace App\CaseStudy\Domain;

/**
 * One client relocation told as a case study (listing card and detail page).
 * Content fields are already localized: they come from the CMS, not from the translation files.
 */
final readonly class CaseStudy
{
    private const WORDS_PER_MINUTE = 200;

    public function __construct(
        public string $slug,
        public string $title,
        /** Short page title for search results (the site adds "Relocation In Paris - "): 38 characters at most. */
        public string $seoTitle,
        public string $excerpt,
        public CaseStudySegment $segment,
        /** Card badge, e.g. "Family relocation". */
        public string $relocationType,
        /** Who the client is, e.g. "Family". */
        public string $clientProfile,
        /** e.g. "Unfurnished housing". */
        public string $housingType,
        /** Length or kind of stay, e.g. "Long-term relocation". */
        public string $stayType,
        /** City the client moved from, e.g. "London". */
        public string $origin,
        /** Where they settled, e.g. "Paris 16e". */
        public string $district,
        /** Days between the kick-off and the signed lease. */
        public int $daysToSettle,
        /** Adviser who handled the relocation (optional). */
        public ?string $agentName,
        /** Adviser's small round photo: asset path or absolute URL. */
        public ?string $agentPhoto,
        /** Cover, 1200x800: asset path or absolute URL (CMS image). */
        public string $photo,
        /** Same cover, 600x400, for srcset. */
        public string $photoSmall,
        public string $photoAlt,
        public \DateTimeImmutable $publishedAt,
        /** Slug of the same case study in the other locale (hreflang, language switch); null until it is translated. */
        public ?string $alternateSlug,
        /** People moving in (optional). */
        public ?int $householdSize,
        /** Monthly rent, in euros (optional). */
        public ?int $budget,
        /** Short story of the project, under the title. */
        public string $summary,
        /** Why this area, above the map. */
        public string $locationText,
        /** Centre of the district, for the map (never the client's address). */
        public float $latitude,
        public float $longitude,
        /** @var list<array{title: string, text: string}> What the client asked for. */
        public array $criteria,
        public string $applicationText,
        /** @var list<CaseStudyDocument> Parts of the tenant file we built. */
        public array $applicationDocuments,
        /** @var list<array{title: string, text: string, photos: list<array{src: string, alt: string}>}> */
        public array $visits,
        public string $securingText,
        /** @var list<array<string, mixed>> Free blocks after the map (text, FAQ, CTA, quick answer, table), the same Sanity blocks as a blog article body. */
        public array $body,
        /** The client's own words and who said them (first name, or role for a company). */
        public string $quote,
        public string $quoteAuthor,
        /** The one case study shown as the big card at the top of the listing. */
        public bool $featured = false,
        /** Package the client chose, linked to the pricing page. */
        public ?CaseStudyOffer $offer = null,
        /** Homes visited before signing, shown in "Projet livré". */
        public ?int $homesVisited = null,
        /** @var list<array{title: string, text: string}> "Notre méthode" steps; empty = the site's standard steps. */
        public array $process = [],
    ) {
    }

    /** Estimated reading time of the detail page, in minutes (at least 1). */
    public function readingMinutes(): int
    {
        $texts = [$this->title, $this->summary, $this->locationText, $this->applicationText, $this->securingText, $this->quote];
        foreach ($this->body as $block) {
            $texts[] = (string) ($block['title'] ?? '');
            foreach (is_array($block['content'] ?? null) ? $block['content'] : [] as $portableText) {
                foreach (is_array($portableText['children'] ?? null) ? $portableText['children'] : [] as $span) {
                    $texts[] = is_string($span['text'] ?? null) ? $span['text'] : '';
                }
            }
        }
        foreach ($this->criteria as $criterion) {
            $texts[] = $criterion['title'].' '.$criterion['text'];
        }
        foreach ($this->visits as $visit) {
            $texts[] = $visit['title'].' '.$visit['text'];
        }

        $words = \count(preg_split('/\s+/u', trim(implode(' ', $texts))) ?: []);

        return max(1, (int) round($words / self::WORDS_PER_MINUTE));
    }
}
