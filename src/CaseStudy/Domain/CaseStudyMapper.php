<?php

namespace App\CaseStudy\Domain;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a CaseStudy from the GROQ projection of SanityCaseStudyRepository::FIELDS.
 * Returns null for a document that misses a field the pages cannot do without
 * (draft published half-filled, deleted agent...), so one bad document never breaks a page.
 */
final class CaseStudyMapper
{
    /** [width, height] of each rendition, cropped around the editor's focal point. */
    private const COVER = [1200, 800];
    private const COVER_SMALL = [600, 400];
    private const AVATAR = [96, 96];
    private const VISIT = [450, 600];

    /** Values picked from a list in the Studio, stored as keys and translated here. */
    private const PROFILES = ['family', 'couple', 'youngProfessional', 'student', 'executive', 'employee', 'entrepreneur', 'atypical', 'company'];
    private const HOUSING = ['unfurnished', 'furnished', 'furnishedStudio'];

    /** Blocks the site knows how to render (Section:ContentBlocks). */
    private const BODY_BLOCKS = ['wysiwygBlock', 'faqBlock', 'ctaBlock', 'quickAnswerBlock', 'tableBlock'];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $row */
    public function fromGroqArray(array $row, string $locale = 'fr'): ?CaseStudy
    {
        $segment = CaseStudySegment::tryFrom((string) ($row['segment'] ?? ''));
        $photo = is_array($row['mainPhoto'] ?? null) ? $row['mainPhoto'] : [];
        $publishedAt = self::date($row['publishedAt'] ?? null);
        // The area sits at a spot of its own inside the arrondissement (stable per case study, same in
        // FR and EN), unless the editor fine-tuned it on the map
        $lat = $row['location']['lat'] ?? null;
        $lng = $row['location']['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            $number = is_int($row['district'] ?? null) ? $row['district'] : ParisArrondissement::fromText((string) ($row['district'] ?? ''));
            // Seed shared by the FR and EN documents, so both languages show the same spot
            $seed = implode('|', [self::string($row, 'publishedAt'), (string) ($row['daysToSettle'] ?? ''), (string) ($row['budget'] ?? '')]);
            [$lat, $lng] = null !== $number ? (ParisArrondissement::spotNear($number, $seed) ?? [null, null]) : [null, null];
        }

        $district = $this->district($row['district'] ?? null, $locale);
        if ('' === $district) {
            return null;
        }

        // Only what a card, a page header and the SEO cannot do without; every other field may be empty
        foreach (['slug', 'title', 'seoTitle', 'shortDescription', 'relocationType'] as $required) {
            if ('' === self::string($row, $required)) {
                return null;
            }
        }
        $days = is_int($row['daysToSettle'] ?? null) ? $row['daysToSettle'] : 0;
        if (null === $segment || '' === self::string($photo, 'url') || null === $publishedAt || $days < 1 || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return new CaseStudy(
            slug: self::string($row, 'slug'),
            title: self::string($row, 'title'),
            seoTitle: self::string($row, 'seoTitle'),
            excerpt: self::string($row, 'shortDescription'),
            segment: $segment,
            relocationType: self::string($row, 'relocationType'),
            clientProfile: $this->choice($row['clientProfile'] ?? null, self::PROFILES, 'caseStudy.profile.', $locale),
            housingType: $this->choice($row['housingType'] ?? null, self::HOUSING, 'caseStudy.housing.', $locale),
            stayType: self::string($row, 'stayType'),
            origin: self::string($row, 'origin'),
            district: $district,
            daysToSettle: $days,
            agentName: '' !== self::string($row, 'agentName') ? self::string($row, 'agentName') : null,
            agentPhoto: '' !== self::string($row, 'agentName')
                ? (self::imageUrl($row['agentPhoto'] ?? null, self::AVATAR) ?? 'medias/website/logo-rounded.webp')
                : null,
            photo: (string) self::imageUrl($photo, self::COVER),
            photoSmall: (string) self::imageUrl($photo, self::COVER_SMALL),
            photoAlt: self::string($photo, 'alt'),
            publishedAt: $publishedAt,
            alternateSlug: '' !== self::string($row, 'alternateSlug') ? self::string($row, 'alternateSlug') : null,
            householdSize: is_int($row['householdSize'] ?? null) && $row['householdSize'] > 0 ? $row['householdSize'] : null,
            budget: is_int($row['budget'] ?? null) && $row['budget'] > 0 ? $row['budget'] : null,
            summary: self::string($row, 'summary'),
            locationText: self::string($row, 'locationText'),
            latitude: (float) $lat,
            longitude: (float) $lng,
            criteria: self::criteria($row['criteria'] ?? null),
            applicationText: self::string($row, 'applicationText'),
            applicationDocuments: self::documents($row['applicationDocuments'] ?? null),
            visits: self::visits($row['visits'] ?? null),
            securingText: self::string($row, 'securingText'),
            body: self::body($row['body'] ?? null),
            table: self::table($row['figures'] ?? null),
            quote: self::string($row, 'quote'),
            quoteAuthor: self::string($row, 'quoteAuthor'),
            featured: true === ($row['featured'] ?? null),
            offer: CaseStudyOffer::tryFrom((string) ($row['offer'] ?? '')),
            homesVisited: is_int($row['homesVisited'] ?? null) && $row['homesVisited'] > 0 ? $row['homesVisited'] : null,
            process: self::criteria($row['process'] ?? null),
        );
    }

    /**
     * Sanity CDN URL of an image ({url, hotspot, crop, dims} from GROQ) at the given size:
     * the editor's crop first (rect), then a crop centred on the hotspot (focal point).
     *
     * @param array{0: int, 1: int} $size
     */
    private static function imageUrl(mixed $image, array $size): ?string
    {
        if (!is_array($image) || '' === self::string($image, 'url')) {
            return null;
        }

        $params = ['w' => $size[0], 'h' => $size[1], 'fit' => 'crop'];
        $crop = is_array($image['crop'] ?? null) ? $image['crop'] : null;
        $width = (int) ($image['dims']['width'] ?? 0);
        $height = (int) ($image['dims']['height'] ?? 0);
        [$left, $top, $right, $bottom] = [0.0, 0.0, 0.0, 0.0];

        if (null !== $crop && $width > 0 && $height > 0) {
            [$left, $top, $right, $bottom] = array_map(
                static fn (string $side): float => max(0.0, (float) ($crop[$side] ?? 0)),
                ['left', 'top', 'right', 'bottom'],
            );
            if ($left + $right < 1 && $top + $bottom < 1 && ($left + $top + $right + $bottom) > 0) {
                $params['rect'] = implode(',', [
                    (int) round($left * $width),
                    (int) round($top * $height),
                    (int) round((1 - $left - $right) * $width),
                    (int) round((1 - $top - $bottom) * $height),
                ]);
            }
        }

        $hotspot = is_array($image['hotspot'] ?? null) ? $image['hotspot'] : null;
        if (null !== $hotspot && is_numeric($hotspot['x'] ?? null) && is_numeric($hotspot['y'] ?? null)) {
            // Hotspot is relative to the whole image: bring it back inside the cropped area
            $fpX = ((float) $hotspot['x'] - $left) / max(0.01, 1 - $left - $right);
            $fpY = ((float) $hotspot['y'] - $top) / max(0.01, 1 - $top - $bottom);
            $params['crop'] = 'focalpoint';
            $params['fp-x'] = round(min(1.0, max(0.0, $fpX)), 3);
            $params['fp-y'] = round(min(1.0, max(0.0, $fpY)), 3);
        }

        return self::string($image, 'url').'?'.http_build_query($params + ['auto' => 'format', 'fm' => 'webp', 'q' => 80]);
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? trim($row[$key]) : '';
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Title + text items (brief criteria, process steps); items without title are dropped.
     *
     * @return list<array{title: string, text: string}>
     */
    private static function criteria(mixed $items): array
    {
        $criteria = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && '' !== self::string($item, 'title')) {
                $criteria[] = ['title' => self::string($item, 'title'), 'text' => self::string($item, 'text')];
            }
        }

        return $criteria;
    }

    /** @return list<CaseStudyDocument> */
    private static function documents(mixed $items): array
    {
        $documents = [];
        foreach (is_array($items) ? $items : [] as $value) {
            $document = is_string($value) ? CaseStudyDocument::tryFrom($value) : null;
            if (null !== $document && !\in_array($document, $documents, true)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /** @return list<array{title: string, text: string, photos: list<array{src: string, alt: string}>}> */
    private static function visits(mixed $items): array
    {
        $visits = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || '' === self::string($item, 'title')) {
                continue;
            }
            $photos = [];
            foreach (is_array($item['photos'] ?? null) ? $item['photos'] : [] as $photo) {
                $src = self::imageUrl($photo, self::VISIT);
                if (null !== $src && is_array($photo)) {
                    $photos[] = ['src' => $src, 'alt' => self::string($photo, 'alt')];
                }
            }
            $visits[] = ['title' => self::string($item, 'title'), 'text' => self::string($item, 'text'), 'photos' => $photos];
        }

        return $visits;
    }

    /**
     * A key picked from a Studio list, translated; an older free-text value is kept as written.
     *
     * @param list<string> $keys
     */
    private function choice(mixed $value, array $keys, string $prefix, string $locale): string
    {
        if (!is_string($value) || '' === trim($value)) {
            return '';
        }

        return \in_array($value, $keys, true) ? $this->translator->trans($prefix.$value, locale: $locale) : trim($value);
    }

    /** Arrondissement number (1-20) as "Paris 1er" / "Paris 16e" / "Paris 16th"; an older free-text value is kept. */
    private function district(mixed $value, string $locale): string
    {
        if (is_int($value) && $value >= 1 && $value <= 20) {
            $ordinal = (new \NumberFormatter($locale, \NumberFormatter::ORDINAL))->format($value);

            return 'Paris '.(false !== $ordinal ? $ordinal : (string) $value);
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Free blocks, kept as Sanity returns them (rendered by Section:ContentBlocks), unknown types dropped.
     *
     * @return list<array<string, mixed>>
     */
    private static function body(mixed $blocks): array
    {
        $body = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (is_array($block) && \in_array($block['_type'] ?? null, self::BODY_BLOCKS, true)) {
                $body[] = $block;
            }
        }

        return $body;
    }

    /** @return array{title: string, caption: ?string, rows: list<list<string>>}|null */
    private static function table(mixed $figures): ?array
    {
        if (!is_array($figures)) {
            return null;
        }
        $rows = [];
        foreach (is_array($figures['table']['rows'] ?? null) ? $figures['table']['rows'] : [] as $row) {
            $cells = array_map(static fn (mixed $cell): string => is_string($cell) ? $cell : '', is_array($row['cells'] ?? null) ? $row['cells'] : []);
            if ([] !== array_filter($cells, static fn (string $cell): bool => '' !== trim($cell))) {
                $rows[] = array_values($cells);
            }
        }

        if (\count($rows) < 2 || '' === self::string($figures, 'title')) {
            return null;
        }

        return [
            'title' => self::string($figures, 'title'),
            'caption' => '' !== self::string($figures, 'caption') ? self::string($figures, 'caption') : null,
            'rows' => $rows,
        ];
    }
}
