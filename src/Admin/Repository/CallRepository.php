<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\Call;
use App\Admin\Service\AlloApiClient;

/**
 * Aggregates Allo calls into the same monthly buckets shape as
 * App\Contact\Repository\ContactRepository::countByMonth() so the dashboard
 * can stitch both series together with the same label set.
 *
 * Both aggregations (monthly + daily) share the same canonical 12-month
 * fetch — AlloApiClient caches that window for 15 min, so the two methods
 * called from the same dashboard render hit a single API roundtrip.
 */
final readonly class CallRepository
{
    public function __construct(
        private AlloApiClient $alloApiClient,
    ) {
    }

    /**
     * @return list<array{ym: string, count: int}>
     */
    public function countByMonth(int $monthsBack = 12): array
    {
        $calls = $this->fetchLast12Months();
        [, $end] = $this->canonicalWindow();

        $byYm = [];
        foreach ($calls as $call) {
            $ym = $call->startedAt->format('Y-m');
            $byYm[$ym] = ($byYm[$ym] ?? 0) + 1;
        }

        $series = [];
        for ($i = $monthsBack; $i >= 1; --$i) {
            $ym = $end->modify('-'.$i.' months')->format('Y-m');
            $series[] = ['ym' => $ym, 'count' => $byYm[$ym] ?? 0];
        }

        return $series;
    }

    /**
     * Counts grouped by Y-m-d inside [$from, $to). $from must lie within the
     * canonical 12-month window — otherwise the cached fetch won't include
     * the data and the result will be wrong (silently). Acceptable here:
     * the only caller is the dashboard, which always asks for a window
     * inside the last 14 days.
     *
     * @return array<string, int>
     */
    public function countByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $calls = $this->fetchLast12Months();

        $byDay = [];
        foreach ($calls as $call) {
            if ($call->startedAt < $from || $call->startedAt >= $to) {
                continue;
            }
            $d = $call->startedAt->format('Y-m-d');
            $byDay[$d] = ($byDay[$d] ?? 0) + 1;
        }

        return $byDay;
    }

    /**
     * @return list<Call>
     */
    private function fetchLast12Months(): array
    {
        [$start, $end] = $this->canonicalWindow();

        return $this->alloApiClient->fetchCallsInWindow($start, $end);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function canonicalWindow(): array
    {
        $end = new \DateTimeImmutable('first day of next month 00:00:00');
        $start = $end->modify('-12 months');

        return [$start, $end];
    }
}
