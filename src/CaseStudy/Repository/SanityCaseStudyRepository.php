<?php

namespace App\CaseStudy\Repository;

use App\CaseStudy\Domain\CaseStudy;
use App\CaseStudy\Domain\CaseStudyMapper;
use App\Shared\Sanity\SanityService;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Case studies from Sanity ("caseStudy" documents, one per language).
 * All reads go through Symfony's cache, invalidated by the Sanity webhook (CACHE_TAG).
 * The whole set is small (tens of documents): one query per locale feeds the listing,
 * the detail pages and the sitemap.
 */
final class SanityCaseStudyRepository implements CaseStudyRepositoryInterface, ResetInterface
{
    public const CACHE_TAG = 'case_study';

    private const TTL = 86400;

    private const FIELDS = '{
        title,
        seoTitle,
        shortDescription,
        "slug": slug.current,
        segment,
        relocationType,
        "mainPhoto": mainPhoto{"url": asset->url, alt, hotspot, crop, "dims": asset->metadata.dimensions},
        featured,
        offer,
        homesVisited,
        publishedAt,
        "agentName": agent->fullName,
        "agentPhoto": agent->photo{"url": asset->url, alt, hotspot, crop, "dims": asset->metadata.dimensions},
        clientProfile,
        householdSize,
        budget,
        housingType,
        stayType,
        origin,
        district,
        daysToSettle,
        location,
        summary,
        process[]{title, text},
        criteria[]{title, text},
        locationText,
        textBlock{title, content},
        applicationText,
        applicationDocuments,
        visits[]{title, text, "photos": photos[]{"url": asset->url, alt, hotspot, crop, "dims": asset->metadata.dimensions}},
        securingText,
        figures{title, caption, table},
        quote,
        quoteAuthor,
        "quotePhoto": quotePhoto{"url": asset->url, alt, hotspot, crop, "dims": asset->metadata.dimensions},
        "alternateSlug": *[_type == "translation.metadata" && references(^._id)][0].translations[_key != $locale][0].value->slug.current
    }';

    /** @var array<string, list<CaseStudy>> Mapped case studies per locale, for the current request */
    private array $mapped = [];

    public function __construct(
        private readonly SanityService $sanityService,
        private readonly TagAwareCacheInterface $cache,
        private readonly CaseStudyMapper $mapper,
    ) {
    }

    public function findAll(string $locale): array
    {
        // A detail page reads the list twice (the case study, then "other case studies"): map it once
        return $this->mapped[$locale] ??= $this->fetchAll($locale);
    }

    /** @return list<CaseStudy> */
    private function fetchAll(string $locale): array
    {
        $rows = $this->cache->get(
            'case_studies_'.$locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::TTL);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    '*[_type == "caseStudy" && language == $locale && defined(slug.current) && !(_id in path("drafts.**"))] | order(publishedAt desc) '.self::FIELDS,
                    ['locale' => $locale]
                );

                return is_array($result) ? $result : [];
            }
        );

        $caseStudies = [];
        foreach ($rows as $row) {
            $caseStudy = is_array($row) ? $this->mapper->fromGroqArray($row) : null;
            if (null !== $caseStudy) {
                $caseStudies[] = $caseStudy;
            }
        }

        return $caseStudies;
    }

    /** Cleared by Symfony between requests (kernel.reset), so the memo never outlives one request. */
    public function reset(): void
    {
        $this->mapped = [];
    }

    public function findOneBySlug(string $slug, string $locale): ?CaseStudy
    {
        foreach ($this->findAll($locale) as $caseStudy) {
            if ($caseStudy->slug === $slug) {
                return $caseStudy;
            }
        }

        return null;
    }
}
