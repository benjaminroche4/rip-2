<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Lease type vs stay duration guard: a mobility lease is capped at 10
 * months, an ALUR lease starts at 1 year.
 */
final class LeaseCompatibility
{
    private function __construct()
    {
    }

    /** Translation key of the mismatch to surface, or null when consistent. */
    public static function mismatchKey(?string $leaseTypesCsv, ?string $stayDuration): ?string
    {
        $leases = explode(',', (string) $leaseTypesCsv);

        if (\in_array('mobility', $leases, true) && 'long' === $stayDuration) {
            return 'admin.dossiers.show.search.leaseMismatch.mobilityLong';
        }
        if (\in_array('alur', $leases, true) && 'short' === $stayDuration) {
            return 'admin.dossiers.show.search.leaseMismatch.alurShort';
        }

        return null;
    }
}
