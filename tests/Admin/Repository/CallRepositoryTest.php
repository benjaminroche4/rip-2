<?php

namespace App\Tests\Admin\Repository;

use App\Admin\Repository\CallRepository;
use App\Admin\Service\AlloApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Repository test wired through a real AlloApiClient with a MockHttpClient.
 * No kernel, no real HTTP. Verifies the full pipeline: HTTP page → DTO → bucket.
 */
final class CallRepositoryTest extends TestCase
{
    public function testCountByMonthReturnsContiguous12BucketsAlignedOnMonths(): void
    {
        $repo = $this->makeRepo([$this->mockPage([], totalPages: 1)]);

        $series = $repo->countByMonth(12);

        self::assertCount(12, $series);
        foreach ($series as $row) {
            self::assertArrayHasKey('ym', $row);
            self::assertArrayHasKey('count', $row);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $row['ym']);
            self::assertSame(0, $row['count']);
        }
    }

    public function testAggregatesCallsByStartedAtMonth(): void
    {
        $end = new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC'));
        $thisMonth = $end->modify('-1 month')->format('Y-m');
        $lastMonth = $end->modify('-2 months')->format('Y-m');
        $twoMonthsAgo = $end->modify('-3 months')->format('Y-m');

        $repo = $this->makeRepo([
            $this->mockPage([
                $this->row('a', $thisMonth.'-15T10:00:00'),
                $this->row('b', $thisMonth.'-16T10:00:00'),
                $this->row('c', $thisMonth.'-17T10:00:00'),
                $this->row('d', $lastMonth.'-15T10:00:00'),
                $this->row('e', $twoMonthsAgo.'-15T10:00:00'),
                $this->row('f', $twoMonthsAgo.'-16T10:00:00'),
            ], totalPages: 1),
        ]);

        $byYm = array_column($repo->countByMonth(12), 'count', 'ym');

        self::assertSame(3, $byYm[$thisMonth]);
        self::assertSame(1, $byYm[$lastMonth]);
        self::assertSame(2, $byYm[$twoMonthsAgo]);
    }

    public function testCountByDayKeyedByDateInsideWindow(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $yesterday = $today->modify('-1 day');

        $repo = $this->makeRepo([
            $this->mockPage([
                $this->row('a', $today->format('Y-m-d').'T10:00:00'),
                $this->row('b', $today->format('Y-m-d').'T15:00:00'),
                $this->row('c', $yesterday->format('Y-m-d').'T11:00:00'),
            ], totalPages: 1),
        ]);

        $from = $today->modify('-7 days');
        $to = $today->modify('+1 day');

        $byDay = $repo->countByDay($from, $to);

        self::assertSame(2, $byDay[$today->format('Y-m-d')]);
        self::assertSame(1, $byDay[$yesterday->format('Y-m-d')]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function makeRepo(array $responses): CallRepository
    {
        $client = new AlloApiClient(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            new NullLogger(),
            apiKey: 'test-key',
            alloNumber: '+33184804344',
        );

        return new CallRepository($client);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function mockPage(array $rows, int $totalPages): MockResponse
    {
        return new MockResponse(
            json_encode([
                'data' => [
                    'results' => $rows,
                    'metadata' => ['total_pages' => $totalPages, 'current_page' => 0],
                ],
            ], JSON_THROW_ON_ERROR),
            [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $startDate): array
    {
        return [
            'id' => $id,
            'start_date' => $startDate,
            'type' => 'INBOUND',
            'length_in_minutes' => 4.2,
            'result' => 'ANSWERED',
        ];
    }
}
