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
     * @return array<string, mixed>
     */
    public function propertyShowPathParams(Property $property, string $locale = 'fr'): array
    {
        $address = $property->address ?? [];

        return [
            '_locale' => $locale,
            'listingType' => $this->slugify($property->listingTypeName ?? ($locale === 'fr' ? 'location' : 'rental')),
            'propertyType' => $this->slugify($property->propertyTypeName ?? ($locale === 'fr' ? 'bien' : 'property')),
            'city' => $this->slugify($address['city'] ?? 'paris'),
            'district' => $this->buildDistrict($address['postalCode'] ?? '', $locale),
            'slug' => $this->slugify($property->slug ?? $property->title ?? $property->id),
        ];
    }

    public function propertyShowPath(Property $property, string $locale = 'fr', bool $absolute = false): string
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
     */
    public function propertyDisplayTitle(Property $property, string $locale = 'fr'): string
    {
        $parts = [];
        $bedrooms = $property->bedroomsLabel;

        if ($bedrooms === 'studio') {
            $parts[] = $this->translator->trans('marketplace.show.title.studio', [], null, $locale);
        } else {
            $parts[] = $property->propertyTypeName ?? $property->title ?? '';
            if ($bedrooms !== null && $bedrooms !== '') {
                $key = $bedrooms === '1'
                    ? 'marketplace.show.title.bedroom'
                    : 'marketplace.show.title.bedrooms';
                $parts[] = $bedrooms . ' ' . $this->translator->trans($key, [], null, $locale);
            }
        }

        if ($property->furnished === 'yes') {
            $parts[] = $this->translator->trans('marketplace.show.title.furnished', [], null, $locale);
        }

        if ($property->squareMeters !== null && $property->squareMeters > 0) {
            $parts[] = $property->squareMeters . ' m²';
        }

        $address = $property->address ?? [];
        if (!empty($address['city'])) {
            $city = $address['city'];
            if (!empty($address['postalCode'])) {
                $city .= ' ' . $this->postalCode->formatPostalCode($address['postalCode'], $locale);
            }
            $parts[] = $city;
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
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
