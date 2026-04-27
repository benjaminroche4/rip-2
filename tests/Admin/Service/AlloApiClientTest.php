<?php

namespace App\Tests\Admin\Service;

use App\Admin\Service\AlloApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pure unit test for AlloApiClient — no kernel, no real HTTP, no real cache.
 *
 * Covers:
 *  - happy path single page
 *  - pagination across multiple pages
 *  - early stop when entire page predates the window
 *  - 429 rate-limit returns empty list, no exception
 *  - 5xx returns empty list, no exception
 *  - missing api key / phone number short-circuits without any HTTP call
 *  - cache hit on the second call (single API roundtrip)
 *  - malformed rows are skipped (don't break the whole batch)
 */
final class AlloApiClientTest extends TestCase
{
    private const API_KEY = 'test-key';
    private const PHONE = '+33184804344';

    public function testReturnsCallsFromSinglePageInsideWindow(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $insideMonth = $end->modify('-1 month')->format('Y-m');

        $client = $this->makeClient([
            $this->mockPage([
                $this->row('call_a', $insideMonth.'-15T10:00:00'),
                $this->row('call_b', $insideMonth.'-20T11:00:00'),
            ], totalPages: 1),
        ]);

        $calls = $client->fetchCallsInWindow($start, $end);

        self::assertCount(2, $calls);
        self::assertSame('call_a', $calls[0]->id);
        self::assertSame('INBOUND', $calls[0]->type);
    }

    public function testPaginatesAcrossPagesUntilTotalPagesReached(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $month = $end->modify('-1 month')->format('Y-m');

        $client = $this->makeClient([
            $this->mockPage([$this->row('a', $month.'-20T10:00:00')], totalPages: 2),
            $this->mockPage([$this->row('b', $month.'-10T10:00:00')], totalPages: 2),
        ]);

        $calls = $client->fetchCallsInWindow($start, $end);

        self::assertCount(2, $calls);
    }

    public function testStopsPagingWhenPageEntirelyOlderThanWindow(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $insideMonth = $end->modify('-1 month')->format('Y-m');
        $tooOldMonth = $end->modify('-12 months')->format('Y-m');

        $client = $this->makeClient([
            $this->mockPage([$this->row('inside', $insideMonth.'-15T10:00:00')], totalPages: 99),
            $this->mockPage([$this->row('too-old', $tooOldMonth.'-15T10:00:00')], totalPages: 99),
            // If the third page were ever requested the test would fail with
            // "no response left in queue" — that's the assertion in disguise.
        ]);

        $calls = $client->fetchCallsInWindow($start, $end);

        self::assertCount(1, $calls);
        self::assertSame('inside', $calls[0]->id);
    }

    public function testReturnsEmptyOn429(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 429]),
        ]);

        self::assertSame([], $client->fetchCallsInWindow($start, $end));
    }

    public function testReturnsEmptyOn5xx(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $client = $this->makeClient([
            new MockResponse('', ['http_code' => 503]),
        ]);

        self::assertSame([], $client->fetchCallsInWindow($start, $end));
    }

    public function testShortCircuitsWhenCredentialsMissing(): void
    {
        [$start, $end] = $this->lastSixMonths();
        // No mock responses needed — no HTTP call should happen at all.
        $client = new AlloApiClient(
            new MockHttpClient([]),
            new ArrayAdapter(),
            new NullLogger(),
            apiKey: '',
            alloNumber: self::PHONE,
        );

        self::assertSame([], $client->fetchCallsInWindow($start, $end));
    }

    public function testCachesResultAcrossInvocations(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $month = $end->modify('-1 month')->format('Y-m');

        // Only one mock response — the second invocation must hit cache.
        $client = $this->makeClient([
            $this->mockPage([$this->row('once', $month.'-15T10:00:00')], totalPages: 1),
        ]);

        $first = $client->fetchCallsInWindow($start, $end);
        $second = $client->fetchCallsInWindow($start, $end);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
    }

    public function testMalformedRowsAreSkipped(): void
    {
        [$start, $end] = $this->lastSixMonths();
        $month = $end->modify('-1 month')->format('Y-m');

        $client = $this->makeClient([
            $this->mockPage([
                $this->row('valid', $month.'-15T10:00:00'),
                ['id' => null, 'start_date' => 'garbage', 'type' => 'INBOUND'],
                ['nope' => true],
            ], totalPages: 1),
        ]);

        $calls = $client->fetchCallsInWindow($start, $end);

        self::assertCount(1, $calls);
        self::assertSame('valid', $calls[0]->id);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function makeClient(array $responses): AlloApiClient
    {
        return new AlloApiClient(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            new NullLogger(),
            apiKey: self::API_KEY,
            alloNumber: self::PHONE,
        );
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
    private function row(string $id, string $startDate, string $type = 'INBOUND'): array
    {
        return [
            'id' => $id,
            'start_date' => $startDate,
            'type' => $type,
            'length_in_minutes' => 4.2,
            'result' => 'ANSWERED',
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function lastSixMonths(): array
    {
        $end = new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC'));
        $start = $end->modify('-6 months');

        return [$start, $end];
    }
}
