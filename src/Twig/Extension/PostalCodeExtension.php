<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PostalCodeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('arrondissement', [$this, 'formatPostalCode']),
        ];
    }

    public function formatPostalCode(?string $postalCode, string $locale = 'fr'): string
    {
        if ($postalCode === null || $postalCode === '') {
            return '';
        }

        if (!str_starts_with($postalCode, '75')) {
            return $postalCode;
        }

        $arr = (int) substr($postalCode, -2);

        if ($locale === 'fr') {
            return $arr === 1 ? '1er arrondissement' : $arr . 'e arrondissement';
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

        return $arr . $suffix . ' arrondissement';
    }
}
