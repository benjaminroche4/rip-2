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
 * Aggregates Stripe payment intents into per-month totals for the admin
 * dashboard chart. Includes every status (succeeded, failed, canceled,
 * etc.) so the chart shows raw payment activity, not just realized revenue.
 *
 * Wraps every Stripe call in try/catch + 10-min cache so the dashboard
 * stays responsive (and renders) even if Stripe is unreachable or the
 * configured API key is invalid.
 */
#[WithMonologChannel('stripe')]
class StripePaymentRepository
{
    private const CACHE_TTL_SECONDS = 600;
    private const CACHE_TTL_RECENT_SECONDS = 300;
    private const CACHE_TTL_ALL_TIME_SECONDS = 86400;
    private const SUCCEEDED_STATUS = 'succeeded';

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
            $item->expiresAfter(self::CACHE_TTL_RECENT_SECONDS);

            try {
                return $this->fetchRecentPayments($limit);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe recent payments fetch failed', [
                    'exception_class' => $e::class,
                    'limit' => $limit,
                ]);
                $item->expiresAfter(60);

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
     * Total successful revenue across the entire account history. Cached
     * 24h since closed past months never change — only the current month
     * delta would justify a faster refresh, but we accept the staleness
     * here in exchange for a single periodic API sweep.
     */
    public function revenueAllTime(): RevenueSnapshot
    {
        $empty = new RevenueSnapshot(0, '', 0);

        if (!$this->stripeClient->isConfigured()) {
            return $empty;
        }

        return $this->cache->get('stripe.payments.revenue.all_time', function (ItemInterface $item) use ($empty): RevenueSnapshot {
            $item->expiresAfter(self::CACHE_TTL_ALL_TIME_SECONDS);

            try {
                // Stripe API has no "since the beginning" sentinel; pick a
                // window large enough to cover any plausible account age.
                $end = new \DateTimeImmutable('first day of next month 00:00:00');
                $start = $end->modify('-20 years');

                return $this->aggregateSucceededRevenue($start, $end);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe all-time revenue fetch failed', [
                    'exception_class' => $e::class,
                ]);
                $item->expiresAfter(60);

                return $empty;
            }
        });
    }

    /**
     * Returns successful revenue per day, from the first ever succeeded
     * payment up to today (inclusive). Days with no payment are filled
     * with 0 so the caller gets a contiguous time series ready to plot.
     * Cached 24h — the trailing edge stales by at most a day, which is
     * acceptable for an "all-time" view.
     *
     * @return list<array{date: string, amount: int}>
     */
    public function revenueByDayAllTime(): array
    {
        if (!$this->stripeClient->isConfigured()) {
            return [];
        }

        return $this->cache->get('stripe.payments.revenue.daily.all_time', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_ALL_TIME_SECONDS);

            try {
                return $this->fetchRevenueByDayAllTime();
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe daily revenue (all time) fetch failed', [
                    'exception_class' => $e::class,
                ]);
                $item->expiresAfter(60);

                return [];
            }
        });
    }

    /**
     * @return list<array{date: string, amount: int}>
     */
    private function fetchRevenueByDayAllTime(): array
    {
        // Stripe API has no "since the beginning" sentinel; pick a window
        // large enough to cover any plausible account age.
        $end = new \DateTimeImmutable('first day of next month 00:00:00');
        $start = $end->modify('-20 years');

        $byDay = [];
        foreach ($this->stripeClient->listPaymentIntents($start, $end) as $intent) {
            if (self::SUCCEEDED_STATUS !== $intent->status) {
                continue;
            }
            $createdAt = (new \DateTimeImmutable('@'.$intent->created))->setTimezone($end->getTimezone());
            $key = $createdAt->format('Y-m-d');
            $byDay[$key] = ($byDay[$key] ?? 0) + (int) $intent->amount;
        }

        if (empty($byDay)) {
            return [];
        }

        ksort($byDay);
        $first = new \DateTimeImmutable((string) array_key_first($byDay));
        $today = new \DateTimeImmutable('today');

        $series = [];
        for ($cursor = $first; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $series[] = ['date' => $key, 'amount' => $byDay[$key] ?? 0];
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

        $cacheKey = sprintf('stripe.payments.revenue.monthly.%d', $monthsBack);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($monthsBack): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                return $this->fetchSuccessfulRevenueByMonth($monthsBack);
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe monthly revenue fetch failed', [
                    'exception_class' => $e::class,
                    'months_back' => $monthsBack,
                ]);
                $item->expiresAfter(60);

                return [];
            }
        });
    }

    /**
     * @return list<MonthlyPaymentTotal>
     */
    private function fetchSuccessfulRevenueByMonth(int $monthsBack): array
    {
        $end = new \DateTimeImmutable('first day of next month 00:00:00');
        $start = $end->modify('-'.$monthsBack.' months');

        $byYm = [];
        $currencyByYm = [];

        foreach ($this->stripeClient->listPaymentIntents($start, $end) as $intent) {
            if (self::SUCCEEDED_STATUS !== $intent->status) {
                continue;
            }
            $createdAt = (new \DateTimeImmutable('@'.$intent->created))->setTimezone($end->getTimezone());
            $ym = $createdAt->format('Y-m');

            $byYm[$ym] = ($byYm[$ym] ?? ['amount' => 0, 'count' => 0]);
            $byYm[$ym]['amount'] += (int) $intent->amount;
            ++$byYm[$ym]['count'];

            if (!isset($currencyByYm[$ym])) {
                $currencyByYm[$ym] = strtoupper((string) $intent->currency);
            }
        }

        $series = [];
        for ($i = $monthsBack; $i >= 1; --$i) {
            $ym = $end->modify('-'.$i.' months')->format('Y-m');
            $series[] = new MonthlyPaymentTotal(
                ym: $ym,
                totalAmount: $byYm[$ym]['amount'] ?? 0,
                currency: $currencyByYm[$ym] ?? '',
                count: $byYm[$ym]['count'] ?? 0,
            );
        }

        return $series;
    }

    /**
     * Returns total successful revenue distributed across the 7 weekdays
     * (1=Monday, 7=Sunday — ISO 8601). Reuses the cached daily series so
     * this is essentially free; lets callers spot which weekday brings
     * the most revenue across the whole account history.
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
     * Returns successful revenue grouped by ISO week (Y-\WW) for the
     * window [$from, $to). Result is keyed by the ISO week label so the
     * caller can stitch together comparison series.
     *
     * @return array<string, int>
     */
    public function successfulRevenueByWeek(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!$this->stripeClient->isConfigured()) {
            return [];
        }

        $cacheKey = sprintf('stripe.payments.revenue.weekly.%s.%s', $from->format('Ymd'), $to->format('Ymd'));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($from, $to): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                $byWeek = [];
                foreach ($this->stripeClient->listPaymentIntents($from, $to) as $intent) {
                    if (self::SUCCEEDED_STATUS !== $intent->status) {
                        continue;
                    }
                    $createdAt = (new \DateTimeImmutable('@'.$intent->created))->setTimezone($from->getTimezone());
                    $key = $createdAt->format('o-W'); // ISO 8601 year-week
                    $byWeek[$key] = ($byWeek[$key] ?? 0) + (int) $intent->amount;
                }

                return $byWeek;
            } catch (\Throwable $e) {
                $this->logger->warning('Stripe weekly revenue fetch failed', [
                    'exception_class' => $e::class,
                ]);
                $item->expiresAfter(60);

                return [];
            }
        });
    }

    /**
     * Sums every succeeded payment intent in [$from, $to) into a single
     * snapshot. Used by the all-time KPI.
     */
    private function aggregateSucceededRevenue(\DateTimeImmutable $from, \DateTimeImmutable $to): RevenueSnapshot
    {
        $total = 0;
        $count = 0;
        $currency = '';

        foreach ($this->stripeClient->listPaymentIntents($from, $to) as $intent) {
            if (self::SUCCEEDED_STATUS !== $intent->status) {
                continue;
            }
            $total += (int) $intent->amount;
            ++$count;
            if ('' === $currency) {
                $currency = strtoupper((string) $intent->currency);
            }
        }

        return new RevenueSnapshot($total, $currency, $count);
    }
}
