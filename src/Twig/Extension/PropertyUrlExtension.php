<?php

namespace App\Twig\Extension;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PropertyUrlExtension extends AbstractExtension
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('property_show_path', [$this, 'propertyShowPath']),
        ];
    }

    /**
     * @param array<string, mixed> $property
     */
    public function propertyShowPath(array $property, string $locale = 'fr', bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'app_property_show',
            [
                '_locale' => $locale,
                'listingType' => $this->slugify($property['listingTypeName'] ?? ($locale === 'fr' ? 'location' : 'rental')),
                'propertyType' => $this->slugify($property['propertyTypeName'] ?? ($locale === 'fr' ? 'bien' : 'property')),
                'city' => $this->slugify($property['address']['city'] ?? 'paris'),
                'district' => $this->buildDistrict($property['address']['postalCode'] ?? '', $locale),
                'slug' => $this->slugify($property['slug'] ?? $property['title'] ?? $property['_id'] ?? 'property'),
            ],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH
        );
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
