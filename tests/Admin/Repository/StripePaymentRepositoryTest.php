<?php

declare(strict_types=1);

namespace App\Tests\Admin\Repository;

use App\Admin\Repository\StripePaymentRepository;
use App\Admin\Service\StripeApiClient;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
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
}
