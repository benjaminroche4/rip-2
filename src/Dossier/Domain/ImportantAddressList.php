<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * "Important addresses" rows of the search card (work, school...): add and
 * remove semantics on the JSON list stored on the snapshot, including the
 * optional Places coordinates so the row can be pinned on the map.
 */
final class ImportantAddressList
{
    /** Common destinations for the "important addresses" rows. */
    public const TYPES = ['work', 'school', 'daycare', 'family', 'gym', 'other'];

    public const MAX = 3;

    private function __construct()
    {
    }

    /**
     * Appends a row; null when there is nothing to add (blank address or
     * list already full). Coordinates are kept only when both parse as
     * plausible lat/lng ('' when typed free-form: the front geocodes those
     * as a fallback).
     *
     * @param list<array{address: string, type: string, lat?: float, lng?: float}> $rows
     *
     * @return list<array{address: string, type: string, lat?: float, lng?: float}>|null
     */
    public static function add(array $rows, string $address, string $type, string $lat = '', string $lng = ''): ?array
    {
        $address = mb_substr(trim($address), 0, 255);
        if ('' === $address || \count($rows) >= self::MAX) {
            return null;
        }

        $row = ['address' => $address, 'type' => $type];
        $latValue = filter_var(trim($lat), \FILTER_VALIDATE_FLOAT);
        $lngValue = filter_var(trim($lng), \FILTER_VALIDATE_FLOAT);
        if (false !== $latValue && false !== $lngValue && abs($latValue) <= 90.0 && abs($lngValue) <= 180.0) {
            $row['lat'] = round($latValue, 6);
            $row['lng'] = round($lngValue, 6);
        }

        $rows[] = $row;

        return $rows;
    }

    /**
     * Removes the row at $index; null when the index no longer exists
     * (double-click on a stale DOM).
     *
     * @param list<array{address: string, type: string, lat?: float, lng?: float}> $rows
     *
     * @return list<array{address: string, type: string, lat?: float, lng?: float}>|null
     */
    public static function remove(array $rows, int $index): ?array
    {
        if (!isset($rows[$index])) {
            return null;
        }

        unset($rows[$index]);

        return array_values($rows);
    }
}
