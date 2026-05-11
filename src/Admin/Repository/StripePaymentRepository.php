<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Domain\MonthlyPaymentTotal;
use App\Admin\Domain\PaymentRow;
use App\Admin\Domain\RevenueSnapshot;
use App\Admin\Service\StripeApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Attribute\WithMonologChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Aggregates Stripe payment intents for the admin Payments dashboard.
 *
 * Performance contract:
 *   - Every public projection (all-time, monthly, weekly, weekday) derives
 *     from one shared `dailyAggregates()` map, so the controller's 4–5
 *     calls trigger at most 2 Stripe sweeps instead of 4 redundant ones.
 *   - The heavy 20-year sweep is cached 24h (history is immutable past a
 *     few weeks) and bounded at the trailing edge by RECENT_WINDOW_DAYS.
 *   - A short delta sweep (RECENT_WINDOW_DAYS days, cached 5 min) keeps
 *     the current month/week KPIs fresh without re-paginating history.
 *
 * Wraps every Stripe call in try/catch so the dashboard stays renderable
 * even if Stripe is unreachable or the configured API key is invalid.
 */
#[WithMonologChannel('stripe')]
class StripePaymentRepository
{
    private const SUCCEEDED_STATUS = 'succeeded';
    private const HISTORICAL_TTL_SECONDS = 86400;
    private const RECENT_TTL_SECONDS = 300;
    private const FAILURE_TTL_SECONDS = 60;
    private const CACHE_TTL_RECENT_PAYMENTS_SECONDS = 300;
    /** Trailing window kept fresh by the short cache; everything before it is served from the long cache. */
    private const RECENT_WINDOW_DAYS = 35;
    /** Sentinel cached when a Stripe fetch failed, so callers can distinguish "outage" from "no payments". */
    private const FAILURE_SENTINEL = ['__failed__' => true];
    private const HISTORY_YEARS = 20;

    public function __construct(
        private readonly StripeApiClient $stripeClient,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the $limit most recent payments (all statuses), newest first,
     * with the customer object expanded so we can read name/email without
     * a second roundtrip per row.
     *
     * @return list<PaymentRow>
     */
    public function recentPayments(int $limit = 100): array
    {
        if (!$this->stripeClient->isConfigured()) {
            return [];
        }

        $cacheKey = sprintf('stripe.payments.recent.%d', $limit);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($limit): array {
            $item->expiresAfter(self::CACHE_TTL_RECENT_PAYMENTS_SECONDS);

            try {
                return $this->fetchRecentPayments($limit);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe recent payments fetch failed', [
                    'exception_class' => $e::class,
                    'limit' => $limit,
                ]);
                $item->expiresAfter(self::FAILURE_TTL_SECONDS);

                return [];
            }
        });
    }

    /**
     * @return list<PaymentRow>
     */
    private function fetchRecentPayments(int $limit): array
    {
        $rows = [];
        foreach ($this->stripeClient->recentPaymentIntents($limit, ['data.customer']) as $intent) {
            $customer = $intent->customer ?? null;
            $billing = $intent->shipping?->name ?? null;
            $name = null;
            $email = null;

            if (\is_object($customer)) {
                $name = $customer->name ?? null;
                $email = $customer->email ?? null;
            }
            // Fallback chain: customer.name → shipping.name → null.
            if (null === $name && null !== $billing) {
                $name = $billing;
            }
            if (null === $email) {
                // PaymentIntent.receipt_email is set when the customer paid
                // as guest or chose to receive a receipt at a different address.
                $email = $intent->receipt_email ?? null;
            }

            $rows[] = new PaymentRow(
                id: $intent->id,
                status: (string) $intent->status,
                amount: (int) $intent->amount,
                currency: strtoupper((string) $intent->currency),
                customerName: $name,
                customerEmail: $email,
                createdAt: (new \DateTimeImmutable('@'.$intent->created)),
            );
        }

        return $rows;
    }

    /**
     * Total successful revenue across the entire account history. Derived
     * from the shared daily map — no extra Stripe call.
     */
    public function revenueAllTime(): RevenueSnapshot
    {
        $empty = new RevenueSnapshot(0, '', 0);
        $aggregates = $this->dailyAggregates();
        if (null === $aggregates) {
            return $empty;
        }

        $total = 0;
        $count = 0;
        $currency = '';
        foreach ($aggregates as $row) {
            $total += $row['amount'];
            $count += $row['count'];
            if ('' === $currency && '' !== $row['currency']) {
                $currency = $row['currency'];
            }
        }

        return new RevenueSnapshot($total, $currency, $count);
    }

    /**
     * Returns successful revenue per day, from the first ever succeeded
     * payment up to today (inclusive). Days with no payment are filled
     * with 0 so the caller gets a contiguous time series ready to plot.
     *
     * @return list<array{date: string, amount: int}>
     */
    public function revenueByDayAllTime(): array
    {
        $aggregates = $this->dailyAggregates();
        if (null === $aggregates || [] === $aggregates) {
            return [];
        }

        ksort($aggregates);
        $first = new \DateTimeImmutable((string) array_key_first($aggregates));
        $today = new \DateTimeImmutable('today');

        $series = [];
        for ($cursor = $first; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $series[] = ['date' => $key, 'amount' => $aggregates[$key]['amount'] ?? 0];
        }

        return $series;
    }

    /**
     * Returns successful revenue grouped by month over the last $monthsBack
     * months, current month included. Empty months are filled with zero.
     *
     * @return list<MonthlyPaymentTotal>
     */
    public function successfulRevenueByMonth(int $monthsBack = 12): array
    {
        if (!$this->stripeClient->isConfigured()) {
            return [];
        }

        $aggregates = $this->dailyAggregates();
        if (null === $aggregates) {
            return [];
        }

        $byYm = [];
        foreach ($aggregates as $date => $row) {
            $ym = substr($date, 0, 7);
            $byYm[$ym] ??= ['amount' => 0, 'count' => 0, 'currency' => ''];
            $byYm[$ym]['amount'] += $row['amount'];
            $byYm[$ym]['count'] += $row['count'];
            if ('' === $byYm[$ym]['currency']) {
                $byYm[$ym]['currency'] = $row['currency'];
            }
        }

        $end = new \DateTimeImmutable('first day of next month 00:00:00');
        $series = [];
        for ($i = $monthsBack; $i >= 1; --$i) {
            $ym = $end->modify('-'.$i.' months')->format('Y-m');
            $series[] = new MonthlyPaymentTotal(
                ym: $ym,
                totalAmount: $byYm[$ym]['amount'] ?? 0,
                currency: $byYm[$ym]['currency'] ?? '',
                count: $byYm[$ym]['count'] ?? 0,
            );
        }

        return $series;
    }

    /**
     * Returns total successful revenue distributed across the 7 weekdays
     * (1=Monday, 7=Sunday — ISO 8601). Derived from the shared daily map.
     *
     * @return array<int, int>
     */
    public function successfulRevenueByWeekday(): array
    {
        $byWeekday = array_fill_keys(range(1, 7), 0);
        foreach ($this->revenueByDayAllTime() as $row) {
            $weekday = (int) (new \DateTimeImmutable($row['date']))->format('N');
            $byWeekday[$weekday] += $row['amount'];
        }

        return $byWeekday;
    }

    /**
     * Returns successful revenue grouped by ISO week (o-W) for the window
     * [$from, $to). Result is keyed by the ISO week label so callers can
     * stitch comparison series together.
     *
     * @return array<string, int>
     */
    public function successfulRevenueByWeek(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!$this->stripeClient->isConfigured()) {
            return [];
        }

        $aggregates = $this->dailyAggregates();
        if (null === $aggregates) {
            return [];
        }

        $tz = $from->getTimezone();
        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');

        $byWeek = [];
        foreach ($aggregates as $date => $row) {
            if ($date < $fromKey || $date >= $toKey) {
                continue;
            }
            $weekKey = (new \DateTimeImmutable($date, $tz))->format('o-W');
            $byWeek[$weekKey] = ($byWeek[$weekKey] ?? 0) + $row['amount'];
        }

        return $byWeek;
    }

    /**
     * Merged daily aggregates (succeeded only) keyed by YYYY-MM-DD, value
     * carries amount/count/currency for the day. Splits the fetch into a
     * long-cached historical slice and a short-cached recent slice so the
     * page stays both fast and fresh. Returns null when either Stripe
     * fetch failed (callers downgrade to an empty projection instead of
     * surfacing "0 payments since the dawn of time").
     *
     * @return array<string, array{amount: int, count: int, currency: string}>|null
     */
    private function dailyAggregates(): ?array
    {
        if (!$this->stripeClient->isConfigured()) {
            return null;
        }

        $historical = $this->historicalAggregates();
        $recent = $this->recentAggregates();

        if (null === $historical || null === $recent) {
            return null;
        }

        // Recent overrides historical on overlapping days (PHP `+` keeps
        // left-side keys), so the trailing edge always reflects the 5-min
        // sweep instead of the day-old snapshot.
        return $recent + $historical;
    }

    /**
     * Heavy 20-year sweep, cached 24h. Stops at the start of the recent
     * window so the cached slice is effectively immutable.
     *
     * @return array<string, array{amount: int, count: int, currency: string}>|null
     */
    private function historicalAggregates(): ?array
    {
        $result = $this->cache->get('stripe.payments.aggregates.historical', function (ItemInterface $item): array {
            $item->expiresAfter(self::HISTORICAL_TTL_SECONDS);

            try {
                $end = (new \DateTimeImmutable('today'))->modify('-'.self::RECENT_WINDOW_DAYS.' days');
                $start = $end->modify('-'.self::HISTORY_YEARS.' years');

                return $this->aggregateByDay($start, $end);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe historical aggregates fetch failed', [
                    'exception_class' => $e::class,
                ]);
                $item->expiresAfter(self::FAILURE_TTL_SECONDS);

                return self::FAILURE_SENTINEL;
            }
        });

        return $this->unwrapSentinel($result);
    }

    /**
     * Lightweight recent-window sweep, cached 5 min.
     *
     * @return array<string, array{amount: int, count: int, currency: string}>|null
     */
    private function recentAggregates(): ?array
    {
        $result = $this->cache->get('stripe.payments.aggregates.recent', function (ItemInterface $item): array {
            $item->expiresAfter(self::RECENT_TTL_SECONDS);

            try {
                $end = new \DateTimeImmutable('first day of next month 00:00:00');
                $start = (new \DateTimeImmutable('today'))->modify('-'.self::RECENT_WINDOW_DAYS.' days');

                return $this->aggregateByDay($start, $end);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe recent aggregates fetch failed', [
                    'exception_class' => $e::class,
                ]);
                $item->expiresAfter(self::FAILURE_TTL_SECONDS);

                return self::FAILURE_SENTINEL;
            }
        });

        return $this->unwrapSentinel($result);
    }

    /**
     * Iterates Stripe payment intents in [$from, $to) and folds the
     * succeeded ones into per-day totals.
     *
     * @return array<string, array{amount: int, count: int, currency: string}>
     */
    private function aggregateByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = $to->getTimezone();
        $byDay = [];

        foreach ($this->stripeClient->listPaymentIntents($from, $to) as $intent) {
            if (self::SUCCEEDED_STATUS !== $intent->status) {
                continue;
            }
            $key = (new \DateTimeImmutable('@'.$intent->created))
                ->setTimezone($tz)
                ->format('Y-m-d');

            $byDay[$key] ??= ['amount' => 0, 'count' => 0, 'currency' => ''];
            $byDay[$key]['amount'] += (int) $intent->amount;
            ++$byDay[$key]['count'];
            if ('' === $byDay[$key]['currency']) {
                $byDay[$key]['currency'] = strtoupper((string) $intent->currency);
            }
        }

        return $byDay;
    }

    /**
     * Returns null when the cached payload is the failure sentinel, the
     * raw array otherwise. Lets callers downgrade an outage to an empty
     * projection without confusing it with a legitimate "no payments yet".
     *
     * @param  array<string, array{amount: int, count: int, currency: string}>|array{__failed__: true} $payload
     * @return array<string, array{amount: int, count: int, currency: string}>|null
     */
    private function unwrapSentinel(array $payload): ?array
    {
        if (isset($payload['__failed__'])) {
            return null;
        }

        /** @var array<string, array{amount: int, count: int, currency: string}> $payload */
        return $payload;
    }
}
