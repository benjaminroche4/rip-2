<?php

namespace App\CaseStudy\Domain;

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
    /** The testimonial photo is shown as a 40 px round avatar (3x for sharp screens). */
    private const QUOTE = [120, 120];

    /** @param array<string, mixed> $row */
    public function fromGroqArray(array $row): ?CaseStudy
    {
        $segment = CaseStudySegment::tryFrom((string) ($row['segment'] ?? ''));
        $photo = is_array($row['mainPhoto'] ?? null) ? $row['mainPhoto'] : [];
        $publishedAt = self::date($row['publishedAt'] ?? null);
        $lat = $row['location']['lat'] ?? null;
        $lng = $row['location']['lng'] ?? null;

        foreach (['slug', 'title', 'seoTitle', 'shortDescription', 'district', 'agentName'] as $required) {
            if ('' === self::string($row, $required)) {
                return null;
            }
        }
        if (null === $segment || '' === self::string($photo, 'url') || null === $publishedAt || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return new CaseStudy(
            slug: self::string($row, 'slug'),
            title: self::string($row, 'title'),
            seoTitle: self::string($row, 'seoTitle'),
            excerpt: self::string($row, 'shortDescription'),
            segment: $segment,
            relocationType: self::string($row, 'relocationType'),
            clientProfile: self::string($row, 'clientProfile'),
            housingType: self::string($row, 'housingType'),
            stayType: self::string($row, 'stayType'),
            origin: self::string($row, 'origin'),
            district: self::string($row, 'district'),
            daysToSettle: (int) ($row['daysToSettle'] ?? 0),
            agentName: self::string($row, 'agentName'),
            agentPhoto: self::imageUrl($row['agentPhoto'] ?? null, self::AVATAR) ?? 'medias/website/logo-rounded.webp',
            photo: (string) self::imageUrl($photo, self::COVER),
            photoSmall: (string) self::imageUrl($photo, self::COVER_SMALL),
            photoAlt: self::string($photo, 'alt'),
            publishedAt: $publishedAt,
            alternateSlug: '' !== self::string($row, 'alternateSlug') ? self::string($row, 'alternateSlug') : null,
            householdSize: max(1, (int) ($row['householdSize'] ?? 1)),
            budget: (int) ($row['budget'] ?? 0),
            summary: self::string($row, 'summary'),
            locationText: self::string($row, 'locationText'),
            latitude: (float) $lat,
            longitude: (float) $lng,
            criteria: self::criteria($row['criteria'] ?? null),
            applicationText: self::string($row, 'applicationText'),
            applicationDocuments: self::documents($row['applicationDocuments'] ?? null),
            visits: self::visits($row['visits'] ?? null),
            securingText: self::string($row, 'securingText'),
            textBlock: self::textBlock($row['textBlock'] ?? null),
            table: self::table($row['figures'] ?? null),
            quote: self::string($row, 'quote'),
            quoteAuthor: self::string($row, 'quoteAuthor'),
            quotePhoto: self::imageUrl($row['quotePhoto'] ?? null, self::QUOTE) ?? (string) self::imageUrl($photo, self::QUOTE),
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
     * Plain paragraphs out of Portable Text (the schema allows normal paragraphs only, no marks).
     *
     * @return array{title: string, paragraphs: list<string>}|null
     */
    private static function textBlock(mixed $block): ?array
    {
        if (!is_array($block) || '' === self::string($block, 'title')) {
            return null;
        }
        $paragraphs = [];
        foreach (is_array($block['content'] ?? null) ? $block['content'] : [] as $portableText) {
            if (!is_array($portableText) || 'block' !== ($portableText['_type'] ?? null)) {
                continue;
            }
            $text = trim(implode('', array_map(
                static fn (mixed $span): string => is_array($span) && is_string($span['text'] ?? null) ? $span['text'] : '',
                is_array($portableText['children'] ?? null) ? $portableText['children'] : [],
            )));
            if ('' !== $text) {
                $paragraphs[] = $text;
            }
        }

        return [] === $paragraphs ? null : ['title' => self::string($block, 'title'), 'paragraphs' => $paragraphs];
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
