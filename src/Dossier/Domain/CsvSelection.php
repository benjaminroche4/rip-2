<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Multi-select chips stored as a CSV string on the search snapshot: parsing
 * and toggle semantics (click adds the value, clicking again removes it).
 */
final class CsvSelection
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function values(?string $csv): array
    {
        return array_values(array_filter(explode(',', (string) $csv)));
    }

    /** Returns the new CSV string ('' when the last value was toggled off). */
    public static function toggle(?string $csv, string $value): string
    {
        $selected = self::values($csv);
        $selected = \in_array($value, $selected, true)
            ? array_values(array_diff($selected, [$value]))
            : [...$selected, $value];

        return implode(',', $selected);
    }
}
