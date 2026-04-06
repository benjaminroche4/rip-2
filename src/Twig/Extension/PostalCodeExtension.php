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

    public function formatPostalCode(?string $postalCode): string
    {
        if ($postalCode === null || $postalCode === '') {
            return '';
        }

        if (!str_starts_with($postalCode, '75')) {
            return $postalCode;
        }

        $arr = (int) substr($postalCode, -2);

        if ($arr === 1) {
            return '1er arrondissement';
        }

        return $arr . 'ème arrondissement';
    }
}
