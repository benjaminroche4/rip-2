<?php

namespace App\Marketplace\Reference;

/**
 * Static catalogue of Paris metro/RER lines (name, brand color, contrasting
 * text color, type). Used to render the colored line badges on property cards.
 */
final class ParisMetroLines
{
    public const LINES = [
        '1' => ['name' => 'Métro 1', 'color' => '#FFCD00', 'textColor' => '#000', 'type' => 'metro'],
        '2' => ['name' => 'Métro 2', 'color' => '#003CA6', 'textColor' => '#fff', 'type' => 'metro'],
        '3' => ['name' => 'Métro 3', 'color' => '#837902', 'textColor' => '#fff', 'type' => 'metro'],
        '3bis' => ['name' => 'Métro 3bis', 'color' => '#6EC4E8', 'textColor' => '#000', 'type' => 'metro'],
        '4' => ['name' => 'Métro 4', 'color' => '#CF009E', 'textColor' => '#fff', 'type' => 'metro'],
        '5' => ['name' => 'Métro 5', 'color' => '#FF7E2E', 'textColor' => '#000', 'type' => 'metro'],
        '6' => ['name' => 'Métro 6', 'color' => '#6ECA97', 'textColor' => '#000', 'type' => 'metro'],
        '7' => ['name' => 'Métro 7', 'color' => '#FA9ABA', 'textColor' => '#000', 'type' => 'metro'],
        '7bis' => ['name' => 'Métro 7bis', 'color' => '#6ECA97', 'textColor' => '#000', 'type' => 'metro'],
        '8' => ['name' => 'Métro 8', 'color' => '#E19BDF', 'textColor' => '#000', 'type' => 'metro'],
        '9' => ['name' => 'Métro 9', 'color' => '#B6BD00', 'textColor' => '#000', 'type' => 'metro'],
        '10' => ['name' => 'Métro 10', 'color' => '#C9910D', 'textColor' => '#fff', 'type' => 'metro'],
        '11' => ['name' => 'Métro 11', 'color' => '#704B1C', 'textColor' => '#fff', 'type' => 'metro'],
        '12' => ['name' => 'Métro 12', 'color' => '#007852', 'textColor' => '#fff', 'type' => 'metro'],
        '13' => ['name' => 'Métro 13', 'color' => '#6EC4E8', 'textColor' => '#000', 'type' => 'metro'],
        '14' => ['name' => 'Métro 14', 'color' => '#62259D', 'textColor' => '#fff', 'type' => 'metro'],
        'A' => ['name' => 'RER A', 'color' => '#F7403A', 'textColor' => '#fff', 'type' => 'rer'],
        'B' => ['name' => 'RER B', 'color' => '#4B92DB', 'textColor' => '#fff', 'type' => 'rer'],
        'C' => ['name' => 'RER C', 'color' => '#F3D311', 'textColor' => '#000', 'type' => 'rer'],
        'D' => ['name' => 'RER D', 'color' => '#45A04A', 'textColor' => '#fff', 'type' => 'rer'],
        'E' => ['name' => 'RER E', 'color' => '#E04DA7', 'textColor' => '#fff', 'type' => 'rer'],
    ];

    public static function getLine(string $id): ?array
    {
        return self::LINES[$id] ?? null;
    }
}
