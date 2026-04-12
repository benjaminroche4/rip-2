<?php

namespace App\Marketplace\Repository;

use App\Service\SanityService;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Centralizes all Sanity queries for the marketplace (properties + property types).
 * All reads go through Symfony's cache (5 min TTL by default).
 */
final class PropertyRepository
{
    public const CACHE_TAG = 'marketplace';

    private const TTL_PROPERTIES = 600;     // 10 min — biens (prix/statut peuvent évoluer)
    private const TTL_TYPES = 3600;         // 1 h   — types de biens (quasi statique)
    private const TTL_COUNT = 1800;         // 30 min — count cosmétique

    public function __construct(
        private readonly SanityService $sanityService,
        private readonly TagAwareCacheInterface $cache,
    ) {}

    /**
     * Full property list for a given locale, used by the marketplace listing + map.
     * Drafts are merged into their published equivalents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(string $locale): array
    {
        return $this->cache->get(
            'marketplace_properties_' . $locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::TTL_PROPERTIES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "property" && language == $lang] | order(_createdAt desc) {
                        _id,
                        "createdAt": _createdAt,
                        "updatedAt": _updatedAt,
                        uniqueId,
                        title,
                        shortDescription,
                        surface,
                        rooms,
                        bedrooms,
                        bathrooms,
                        "monthlyRent": rents.monthlyRent,
                        "priceOnRequest": rents.priceOnRequest,
                        "chargesIncludes": chargesIncludes,
                        "showCategoryOnCard": showCategoryOnCard,
                        currency,
                        status,
                        leaseType,
                        "listingTypeName": listingType->name,
                        longTerm,
                        midTerm,
                        categoryFlags,
                        "slug": slug.current,
                        "address": address{city, postalCode, street, number},
                        "mainPhoto": {
                            "url": mainPhoto.asset->url,
                            "alt": mainPhoto.alt
                        },
                        "photos": photos[]{
                            "url": asset->url,
                            "alt": alt
                        },
                        availableDate,
                        "agentPhoto": agent->photo.asset->url,
                        "agentName": agent->fullName,
                        "photoCount": count(photos),
                        "categoryName": categories[0]->name,
                        "location": location{lat, lng},
                        "elevator": equipment.elevator,
                        "furnished": main.furnished,
                        "bedroomsLabel": main.bedrooms,
                        "squareMeters": main.squareMeters,
                        "propertyTypeName": propertyType->name,
                        "propertyTypeSlug": propertyType->slug.current,
                        "propertyTypeLang": propertyType->language
                    }',
                    ['lang' => $locale]
                );

                if (!is_array($results)) {
                    return [];
                }

                return $this->dedupeDrafts($results);
            }
        );
    }

    /**
     * Lightweight projection for JSON-LD schema.org markup. Limited to N items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForSchema(string $locale, int $limit = 12): array
    {
        return $this->cache->get(
            'marketplace_schema_properties_' . $locale . '_' . $limit,
            function (ItemInterface $item) use ($locale, $limit): array {
                $item->expiresAfter(self::TTL_PROPERTIES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    sprintf(
                        '*[_type == "property" && language == $lang && status != "rented"] | order(_createdAt desc) [0..%d] {
                            _id,
                            title,
                            "slug": slug.current,
                            "monthlyRent": rents.monthlyRent,
                            "priceOnRequest": rents.priceOnRequest,
                            rooms,
                            bedrooms,
                            "squareMeters": main.squareMeters,
                            status,
                            "address": address{city, postalCode},
                            "image": mainPhoto.asset->url,
                            "propertyTypeName": propertyType->name
                        }',
                        max(0, $limit - 1)
                    ),
                    ['lang' => $locale]
                );

                return is_array($results) ? $results : [];
            }
        );
    }

    public function countAvailable(string $locale): int
    {
        return $this->cache->get(
            'marketplace_schema_properties_count_' . $locale,
            function (ItemInterface $item) use ($locale): int {
                $item->expiresAfter(self::TTL_COUNT);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    'count(*[_type == "property" && language == $lang && status != "rented"])',
                    ['lang' => $locale]
                );

                return is_int($result) ? $result : 0;
            }
        );
    }

    /**
     * Returns single property by slug (used by detail page).
     *
     * @return array<string, mixed>|null
     */
    public function findOneBySlug(string $slug, string $locale): ?array
    {
        $all = $this->findAll($locale);
        foreach ($all as $property) {
            if (($property['slug'] ?? null) === $slug) {
                return $property;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOneById(string $id, string $locale): ?array
    {
        $needle = str_starts_with($id, 'drafts.') ? substr($id, 7) : $id;
        foreach ($this->findAll($locale) as $property) {
            $candidate = str_starts_with($property['_id'], 'drafts.') ? substr($property['_id'], 7) : $property['_id'];
            if ($candidate === $needle) {
                return $property;
            }
        }
        return null;
    }

    /**
     * @return array<string, string> slug => name
     */
    public function findPropertyTypes(string $locale): array
    {
        return $this->cache->get(
            'property_types_' . $locale,
            function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::TTL_TYPES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "propertyType" && language == $lang] | order(name asc) { "slug": slug.current, name }',
                    ['lang' => $locale]
                );

                if (!is_array($results)) {
                    return [];
                }

                $types = [];
                foreach ($results as $type) {
                    if (!empty($type['slug']) && !empty($type['name'])) {
                        $types[$type['slug']] = $type['name'];
                    }
                }
                return $types;
            }
        );
    }

    /**
     * Given a propertyType slug, returns all slugs sharing the same name across languages.
     *
     * @return array<int, string>
     */
    public function findMatchingTypeSlugs(string $slug): array
    {
        return $this->cache->get(
            'property_type_slugs_' . $slug,
            function (ItemInterface $item) use ($slug): array {
                $item->expiresAfter(self::TTL_TYPES);
                $item->tag(self::CACHE_TAG);

                $results = $this->sanityService->query(
                    '*[_type == "propertyType" && lower(name) == lower(*[_type == "propertyType" && slug.current == $slug][0].name)]{ "slug": slug.current }',
                    ['slug' => $slug]
                );

                if (!is_array($results) || empty($results)) {
                    return [$slug];
                }

                return array_map(fn ($t) => $t['slug'], $results);
            }
        );
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDrafts(array $results): array
    {
        $seen = [];
        $deduped = [];
        foreach ($results as $property) {
            $isDraft = str_starts_with($property['_id'], 'drafts.');
            $baseId = $isDraft ? substr($property['_id'], 7) : $property['_id'];

            if (!isset($seen[$baseId])) {
                $seen[$baseId] = true;
                $deduped[] = $property;
            } elseif (!$isDraft) {
                foreach ($deduped as $k => $p) {
                    if (str_replace('drafts.', '', $p['_id']) === $baseId) {
                        $deduped[$k] = $property;
                        break;
                    }
                }
            }
        }

        return $deduped;
    }
}
