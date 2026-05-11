<?php

declare(strict_types=1);

namespace App\Tests\Admin\Repository;

use App\Admin\Repository\StripePaymentRepository;
use App\Admin\Service\StripeApiClient;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class StripePaymentRepositoryTest extends \PHPUnit\Framework\TestCase
{
    public function testReturnsEmptyWhenStripeKeyIsNotConfigured(): void
    {
        $client = $this->makeClient(configured: false);
        $repo = $this->makeRepo($client);

        self::assertSame([], $repo->successfulRevenueByMonth(12));
    }

    public function testReturnsEmptyAndDoesNotThrowWhenStripeFails(): void
    {
        $client = $this->makeClient(
            configured: true,
            listFn: static function (): iterable {
                throw new \RuntimeException('boom');
            },
        );
        $repo = $this->makeRepo($client);

        // No exception bubbles up — page must still render.
        self::assertSame([], $repo->successfulRevenueByMonth(12));
    }

    public function testSuccessfulRevenueByMonthAggregatesOnlySucceededIntents(): void
    {
        $now = new \DateTimeImmutable('first day of this month 12:00:00');
        // 5000 + 2500 succeeded this month, plus a 9999 failed that must be ignored.
        $thisMonthOk = $this->fakeIntent(amount: 5000, currency: 'chf', createdAt: $now, status: 'succeeded');
        $thisMonthOk2 = $this->fakeIntent(amount: 2500, currency: 'chf', createdAt: $now->modify('+1 day'), status: 'succeeded');
        $thisMonthFailed = $this->fakeIntent(amount: 9999, currency: 'chf', createdAt: $now->modify('+2 days'), status: 'requires_payment_method');
        $lastMonthOk = $this->fakeIntent(amount: 10000, currency: 'chf', createdAt: $now->modify('-1 month'), status: 'succeeded');

        $client = $this->makeClient(
            configured: true,
            listFn: static fn (): iterable => yield from [$thisMonthOk, $thisMonthOk2, $thisMonthFailed, $lastMonthOk],
        );
        $repo = $this->makeRepo($client);

        $totals = $repo->successfulRevenueByMonth(12);

        self::assertCount(12, $totals);

        $byYm = [];
        foreach ($totals as $bucket) {
            $byYm[$bucket->ym] = $bucket;
        }

        // Failed intent (9999) is excluded — only 5000+2500 remain.
        self::assertSame(7500, $byYm[$now->format('Y-m')]->totalAmount);
        self::assertSame(2, $byYm[$now->format('Y-m')]->count);
        self::assertSame('CHF', $byYm[$now->format('Y-m')]->currency);

        self::assertSame(10000, $byYm[$now->modify('-1 month')->format('Y-m')]->totalAmount);
        self::assertSame(1, $byYm[$now->modify('-1 month')->format('Y-m')]->count);

        // Empty bucket ~6 months ago → zero amount, zero count, empty currency.
        $sixMonthsAgo = $now->modify('-6 months')->format('Y-m');
        self::assertSame(0, $byYm[$sixMonthsAgo]->totalAmount);
        self::assertSame(0, $byYm[$sixMonthsAgo]->count);
        self::assertSame('', $byYm[$sixMonthsAgo]->currency);
    }

    public function testRevenueAllTimeSumsEverySucceededIntent(): void
    {
        $today = new \DateTimeImmutable('today');
        $intents = [
            $this->fakeIntent(amount: 5000, currency: 'eur', createdAt: $today, status: 'succeeded'),
            $this->fakeIntent(amount: 2500, currency: 'eur', createdAt: $today->modify('-2 years'), status: 'succeeded'),
            $this->fakeIntent(amount: 9999, currency: 'eur', createdAt: $today, status: 'requires_payment_method'),
        ];
        $client = $this->makeClient(
            configured: true,
            listFn: static fn (\DateTimeImmutable $from, \DateTimeImmutable $to): iterable => self::filterByWindow($intents, $from, $to),
        );
        $repo = $this->makeRepo($client);

        $snapshot = $repo->revenueAllTime();

        self::assertSame(7500, $snapshot->totalAmount, 'Failed intent must be excluded from the sum.');
        self::assertSame(2, $snapshot->count);
        self::assertSame('EUR', $snapshot->currency);
    }

    public function testRevenueByDayAllTimeFillsGapsBetweenFirstAndToday(): void
    {
        $today = new \DateTimeImmutable('today');
        $intents = [
            $this->fakeIntent(amount: 1000, currency: 'eur', createdAt: $today, status: 'succeeded'),
            $this->fakeIntent(amount: 1000, currency: 'eur', createdAt: $today->modify('-2 days'), status: 'succeeded'),
        ];
        $client = $this->makeClient(
            configured: true,
            listFn: static fn (\DateTimeImmutable $from, \DateTimeImmutable $to): iterable => self::filterByWindow($intents, $from, $to),
        );
        $repo = $this->makeRepo($client);

        $series = $repo->revenueByDayAllTime();

        // Contiguous 3-day series: D-2, D-1 (gap → 0), today.
        self::assertCount(3, $series);
        self::assertSame($today->modify('-2 days')->format('Y-m-d'), $series[0]['date']);
        self::assertSame(1000, $series[0]['amount']);
        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $series[1]['date']);
        self::assertSame(0, $series[1]['amount']);
        self::assertSame($today->format('Y-m-d'), $series[2]['date']);
        self::assertSame(1000, $series[2]['amount']);
    }

    /**
     * Locks the perf contract: rendering the full Payments page calls 5 public
     * projections; collectively they must trigger at most 2 Stripe sweeps
     * (one historical, one recent). Any future change that re-introduces a
     * per-projection fetch will fail here.
     */
    public function testFullDashboardCycleHitsStripeAtMostTwice(): void
    {
        $today = new \DateTimeImmutable('today');
        $intents = [
            $this->fakeIntent(amount: 5000, currency: 'eur', createdAt: $today, status: 'succeeded'),
            $this->fakeIntent(amount: 2500, currency: 'eur', createdAt: $today->modify('-3 months'), status: 'succeeded'),
        ];

        $calls = 0;
        $client = $this->makeClient(
            configured: true,
            listFn: static function (\DateTimeImmutable $from, \DateTimeImmutable $to) use ($intents, &$calls): iterable {
                ++$calls;

                return self::filterByWindow($intents, $from, $to);
            },
        );
        $repo = $this->makeRepo($client);

        // Mirrors the controller: every public projection used on the page.
        $repo->revenueAllTime();
        $repo->revenueByDayAllTime();
        $repo->successfulRevenueByMonth(12);
        $repo->successfulRevenueByWeekday();
        $weeklyFrom = (new \DateTimeImmutable('today'))->modify('-7 weeks');
        $weeklyTo = (new \DateTimeImmutable('today'))->modify('+1 week');
        $repo->successfulRevenueByWeek($weeklyFrom, $weeklyTo);

        self::assertLessThanOrEqual(2, $calls, sprintf('Expected ≤2 Stripe sweeps for a full page render, got %d.', $calls));
    }

    private function makeClient(bool $configured, ?\Closure $listFn = null): StripeApiClient
    {
        return new class($configured, $listFn) extends StripeApiClient {
            public function __construct(
                private readonly bool $configured,
                private readonly ?\Closure $listFn,
            ) {
                parent::__construct('');
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function listPaymentIntents(\DateTimeImmutable $from, \DateTimeImmutable $to, array $expand = []): iterable
            {
                if (null === $this->listFn) {
                    return [];
                }

                return ($this->listFn)($from, $to);
            }

            public function recentPaymentIntents(int $limit, array $expand = []): array
            {
                if (null === $this->listFn) {
                    return [];
                }

                $result = ($this->listFn)(new \DateTimeImmutable('@0'), new \DateTimeImmutable('@'.PHP_INT_MAX));

                return is_array($result) ? array_slice($result, 0, $limit) : iterator_to_array($result, false);
            }
        };
    }

    private function makeRepo(StripeApiClient $client): StripePaymentRepository
    {
        /** @var CacheInterface $cache */
        $cache = new ArrayAdapter();

        return new StripePaymentRepository($client, $cache, new NullLogger());
    }

    private function fakeIntent(int $amount, string $currency, \DateTimeImmutable $createdAt, string $status = 'succeeded'): object
    {
        return new class($amount, $currency, $createdAt->getTimestamp(), $status) {
            public function __construct(
                public readonly int $amount,
                public readonly string $currency,
                public readonly int $created,
                public readonly string $status,
            ) {
            }
        };
    }

    /**
     * @param  list<object>  $intents
     * @return iterable<object>
     */
    private static function filterByWindow(array $intents, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        foreach ($intents as $intent) {
            if ($intent->created >= $fromTs && $intent->created < $toTs) {
                yield $intent;
            }
        }
    }
}
