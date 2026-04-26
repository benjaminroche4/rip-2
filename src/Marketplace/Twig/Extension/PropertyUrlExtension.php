<?php

namespace App\Marketplace\Twig\Extension;

use App\Marketplace\Domain\Property;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PropertyUrlExtension extends AbstractExtension
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly PostalCodeExtension $postalCode,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('property_show_path', [$this, 'propertyShowPath']),
            new TwigFunction('property_show_path_params', [$this, 'propertyShowPathParams']),
            new TwigFunction('property_display_title', [$this, 'propertyDisplayTitle']),
        ];
    }

    /**
     * Returns the route parameters array for `app_property_show` — useful when
     * you need to pass params to `path()` directly (e.g. language switcher).
     *
     * Accepts either a Property DTO (modern) or a raw GROQ array (callers not
     * yet migrated, e.g. SiteMapEventListener iterating over findAll).
     *
     * @param Property|array<string, mixed> $property
     * @return array<string, mixed>
     */
    public function propertyShowPathParams(Property|array $property, string $locale = 'fr'): array
    {
        $address = $this->get($property, 'address') ?? [];

        return [
            '_locale' => $locale,
            'listingType' => $this->slugify($this->get($property, 'listingTypeName') ?? ($locale === 'fr' ? 'location' : 'rental')),
            'propertyType' => $this->slugify($this->get($property, 'propertyTypeName') ?? ($locale === 'fr' ? 'bien' : 'property')),
            'city' => $this->slugify($address['city'] ?? 'paris'),
            'district' => $this->buildDistrict($address['postalCode'] ?? '', $locale),
            'slug' => $this->slugify($this->get($property, 'slug') ?? $this->get($property, 'title') ?? $this->getId($property) ?? 'property'),
        ];
    }

    /**
     * @param Property|array<string, mixed> $property
     */
    public function propertyShowPath(Property|array $property, string $locale = 'fr', bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'app_property_show',
            $this->propertyShowPathParams($property, $locale),
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    /**
     * Builds the structured SEO-friendly display title used in the <title> tag,
     * sitemap, breadcrumb and JSON-LD — e.g.:
     *   "Appartement 2 chambres meublé 65 m² Paris 11e arrondissement"
     *   "Studio meublé 30 m² Paris 8e arrondissement"
     *   "Studio Levallois-Perret 92300"
     *
     * @param Property|array<string, mixed> $property
     */
    public function propertyDisplayTitle(Property|array $property, string $locale = 'fr'): string
    {
        $parts = [];
        $bedrooms = $this->get($property, 'bedroomsLabel');

        if ($bedrooms === 'studio') {
            $parts[] = $this->translator->trans('marketplace.show.title.studio', [], null, $locale);
        } else {
            $parts[] = $this->get($property, 'propertyTypeName') ?? $this->get($property, 'title') ?? '';
            if ($bedrooms) {
                $key = $bedrooms === '1'
                    ? 'marketplace.show.title.bedroom'
                    : 'marketplace.show.title.bedrooms';
                $parts[] = $bedrooms . ' ' . $this->translator->trans($key, [], null, $locale);
            }
        }

        if ($this->get($property, 'furnished') === 'yes') {
            $parts[] = $this->translator->trans('marketplace.show.title.furnished', [], null, $locale);
        }

        $squareMeters = $this->get($property, 'squareMeters');
        if (!empty($squareMeters)) {
            $parts[] = $squareMeters . ' m²';
        }

        $address = $this->get($property, 'address') ?? [];
        if (!empty($address['city'])) {
            $city = $address['city'];
            if (!empty($address['postalCode'])) {
                $city .= ' ' . $this->postalCode->formatPostalCode($address['postalCode'], $locale);
            }
            $parts[] = $city;
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param Property|array<string, mixed> $property
     */
    private function get(Property|array $property, string $field): mixed
    {
        if (is_array($property)) {
            return $property[$field] ?? null;
        }
        return $property->{$field} ?? null;
    }

    /**
     * @param Property|array<string, mixed> $property
     */
    private function getId(Property|array $property): ?string
    {
        if (is_array($property)) {
            return $property['_id'] ?? null;
        }
        return $property->id;
    }

    private function slugify(string $value): string
    {
        $slug = $this->slugger->slug($value)->lower()->toString();

        return $slug !== '' ? $slug : 'other';
    }

    private function buildDistrict(string $postalCode, string $locale): string
    {
        if (!str_starts_with($postalCode, '75') || strlen($postalCode) !== 5) {
            return $this->slugify($postalCode);
        }

        $arr = (int) substr($postalCode, -2);

        if ($locale === 'fr') {
            return $arr === 1 ? '1er' : $arr . 'eme';
        }

        $suffix = match ($arr % 100) {
            11, 12, 13 => 'th',
            default => match ($arr % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            },
        };

        return $arr . $suffix;
    }
}
