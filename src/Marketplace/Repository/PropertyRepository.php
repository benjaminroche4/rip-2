<?php

namespace App\Marketplace\Repository;

use App\Marketplace\Domain\Property;
use App\Marketplace\Domain\PropertyMapper;
use App\Shared\Sanity\SanityService;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Centralizes all Sanity queries for the marketplace (properties + property types).
 * All reads go through Symfony's cache, invalidated by the Sanity webhook.
 */
final class PropertyRepository
{
    public const CACHE_TAG = 'marketplace';

    private const TTL_PROPERTIES = 86400;
    private const TTL_TYPES = 604800;
    private const TTL_COUNT = 86400;

    private const PROPERTY_PROJECTION = '{
        _id,
        "createdAt": _createdAt,
        "updatedAt": _updatedAt,
        uniqueId,
        title,
        metaDescription,
        "bedrooms": main.bedrooms,
        "bathrooms": main.bathrooms,
        "monthlyRent": rents.monthlyRent,
        priceOnRequest,
        chargesIncludes,
        "showCategoryOnCard": showCategoryOnCard,
        status,
        leaseType,
        "listingTypeName": listingType->name,
        longTerm,
        midTerm,
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
        "tenant": tenant->{
            tenantType,
            fullName,
            companyName,
            email,
            phone,
            website,
            "logo": logo.asset->url,
            "logoAlt": logo.alt
        },
        "photoCount": count(photos),
        "categoryName": categories[0]->name,
        "categoryList": categories[]->{"name": name, "slug": slug.current},
        "location": location{lat, lng},
        "elevator": equipment.elevator,
        equipment,
        "floor": floors.floor,
        "totalFloors": floors.totalFloors,
        exposure,
        view,
        "constructionYear": buildingYears.constructionYear,
        "renovationYear": buildingYears.renovationYear,
        "faq": faq[]{_key, question, answer},
        "extraFees": extraFees[]{_key, amount, feeType},
        "furnished": main.furnished,
        "bedroomsLabel": main.bedrooms,
        "squareMeters": main.squareMeters,
        "propertyTypeName": propertyType->name,
        "propertyTypeSlug": propertyType->slug.current,
        "propertyTypeLang": propertyType->language,
        "metro": metro,
        "rer": rer,
        tags,
        internalNotes,
        description,
        "alternateProperty": *[_type == "translation.metadata" && references(^._id)]{
            translations[_key != $lang]{
                _key,
                "slug": value->slug.current,
                "title": value->title,
                "listingTypeName": value->listingType->name,
                "propertyTypeName": value->propertyType->name,
                "address": value->address{city, postalCode}
            }
        }[0].translations[0]
    }';

    public function __construct(
        private readonly SanityService $sanityService,
        private readonly TagAwareCacheInterface $cache,
        private readonly PropertyMapper $mapper,
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
                    '*[_type == "property" && language == $lang && !(_id in path("drafts.**"))] | order(_createdAt desc) ' . self::PROPERTY_PROJECTION,
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
                        '*[_type == "property" && language == $lang && status != "rented" && !(_id in path("drafts.**"))] | order(_createdAt desc) [0..%d] {
                            _id,
                            title,
                            "slug": slug.current,
                            "monthlyRent": rents.monthlyRent,
                            priceOnRequest,
                            "bedrooms": main.bedrooms,
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
                    'count(*[_type == "property" && language == $lang && status != "rented" && !(_id in path("drafts.**"))])',
                    ['lang' => $locale]
                );

                return is_int($result) ? $result : 0;
            }
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOneBySlug(string $slug, string $locale): ?array
    {
        return $this->cache->get(
            'marketplace_property_slug_' . $locale . '_' . md5($slug),
            function (ItemInterface $item) use ($slug, $locale): ?array {
                $item->expiresAfter(self::TTL_PROPERTIES);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    '*[_type == "property" && language == $lang && slug.current == $slug && !(_id in path("drafts.**"))][0] ' . self::PROPERTY_PROJECTION,
                    ['lang' => $locale, 'slug' => $slug]
                );

                return is_array($result) ? $result : null;
            }
        );
    }

    /**
     * Looks up a property by its Sanity _id. Both the draft (`drafts.X`) and the
     * published copy match the same logical entity.
     */
    public function findOneById(string $id, string $locale): ?Property
    {
        $publishedId = str_starts_with($id, 'drafts.') ? substr($id, 7) : $id;

        $row = $this->cache->get(
            'marketplace_property_id_' . $locale . '_' . md5($publishedId),
            function (ItemInterface $item) use ($publishedId, $locale): ?array {
                $item->expiresAfter(self::TTL_PROPERTIES);
                $item->tag(self::CACHE_TAG);

                $result = $this->sanityService->query(
                    '*[_type == "property" && language == $lang && (_id == $id || _id == $draftId)] | order(_id asc)[0] ' . self::PROPERTY_PROJECTION,
                    ['lang' => $locale, 'id' => $publishedId, 'draftId' => 'drafts.' . $publishedId]
                );

                return is_array($result) ? $result : null;
            }
        );

        return $row !== null ? $this->mapper->fromGroqArray($row) : null;
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
