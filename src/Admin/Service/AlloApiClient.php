<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Admin\Domain\Call;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the Allo "search calls" endpoint
 * (https://api.withallo.com/v1/api/calls).
 *
 * Design constraints (CLAUDE.md):
 *  - o2switch shared hosting → no async workers, all calls happen on the
 *    request thread. Cache via cache.app (filesystem) is mandatory: the
 *    dashboard would otherwise pull 12 months of pages on every load.
 *  - Allo has no date-range query param → we paginate, map every result to
 *    a Call DTO, then let CallRepository filter by month in PHP.
 *  - Failure modes are non-blocking: a 5xx, a 429, a timeout or a malformed
 *    payload returns an empty list and a warning log. The dashboard renders
 *    "0 calls" rather than crashing.
 */
final readonly class AlloApiClient
{
    private const ENDPOINT = 'https://api.withallo.com/v1/api/calls';
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 50;             // hard cap = 5000 calls / fetch (failsafe)
    private const TIMEOUT_SECONDS = 5;
    private const CACHE_TTL = 900;            // 15 min — current month only
    private const HISTORY_CACHE_TTL = 2_592_000; // 30 jours — les mois passés sont immuables
    private const CACHE_KEY_PREFIX = 'allo.calls.window.';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%allo_api_key%')]
        private string $apiKey,
        #[Autowire('%allo_phone_number%')]
        private string $alloNumber,
    ) {
    }

    /**
     * Fetches every call in [$start, $end), cached PER MONTH: past months
     * never change (30-day TTL), only the current month is refreshed every
     * 15 min. The Allo API has no date filter, so a cold history costs one
     * deep pagination — once — then steady-state only walks the current
     * month's pages.
     *
     * @return list<Call>
     */
    public function fetchCallsInWindow(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ('' === $this->apiKey || '' === $this->alloNumber) {
            // No credentials configured (typical in fresh installs / CI without
            // the Allo secret). Treat as zero calls, no log spam.
            return [];
        }

        $currentMonth = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);

        // Month buckets covering the window.
        $months = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0);
        while ($cursor < $end) {
            $months[] = $cursor;
            $cursor = $cursor->modify('+1 month');
        }

        $keys = array_map(static fn (\DateTimeImmutable $m): string => self::CACHE_KEY_PREFIX.'month.'.$m->format('Y-m'), $months);
        $items = $this->itemsByKey($keys);

        $missingFrom = null;
        foreach ($months as $i => $month) {
            if (!$items[$keys[$i]]->isHit()) {
                $missingFrom ??= $month;
            }
        }

        if (null !== $missingFrom) {
            // One pagination covers every missing month up to now, bucketed
            // then stored with the right TTL each.
            $fetched = $this->paginate($missingFrom, $end);
            $buckets = [];
            foreach ($fetched as $call) {
                $buckets[$call->startedAt->format('Y-m')][] = $call;
            }
            foreach ($months as $i => $month) {
                $item = $items[$keys[$i]];
                if ($item->isHit() && $month < $missingFrom) {
                    continue;
                }
                $item->set($buckets[$month->format('Y-m')] ?? []);
                $item->expiresAfter($month >= $currentMonth ? self::CACHE_TTL : self::HISTORY_CACHE_TTL);
                $this->cache->save($item);
            }
            $items = $this->itemsByKey($keys);
        }

        $calls = [];
        foreach ($keys as $key) {
            foreach ($items[$key]->get() ?? [] as $call) {
                if ($call->startedAt >= $start && $call->startedAt < $end) {
                    $calls[] = $call;
                }
            }
        }

        return $calls;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, \Psr\Cache\CacheItemInterface>
     */
    private function itemsByKey(array $keys): array
    {
        $items = [];
        foreach ($this->cache->getItems($keys) as $key => $item) {
            $items[$key] = $item;
        }

        return $items;
    }

    /**
     * @return list<Call>
     */
    private function paginate(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $calls = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $payload = $this->fetchPage($page);
            if (null === $payload) {
                return $calls;
            }

            $results = $payload['data']['results'] ?? [];
            if (!\is_array($results) || [] === $results) {
                return $calls;
            }

            $oldestOnPage = null;
            foreach ($results as $row) {
                $call = $this->mapRow($row);
                if (null === $call) {
                    continue;
                }
                if ($call->startedAt >= $start && $call->startedAt < $end) {
                    $calls[] = $call;
                }
                if (null === $oldestOnPage || $call->startedAt < $oldestOnPage) {
                    $oldestOnPage = $call->startedAt;
                }
            }

            // The Allo response is ordered by start_date desc (latest first).
            // Once an entire page predates our window, no later page will
            // come back into it — stop paging.
            if (null !== $oldestOnPage && $oldestOnPage < $start) {
                return $calls;
            }

            $totalPages = (int) ($payload['data']['metadata']['pagination']['total_pages'] ?? 0);
            if ($page + 1 >= $totalPages) {
                return $calls;
            }
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $page): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'headers' => [
                    // Allo expects the API key raw in the Authorization header,
                    // without a Bearer/Token scheme prefix (verified via curl
                    // probe — Bearer returns 401, raw key returns 200).
                    'Authorization' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'allo_number' => $this->alloNumber,
                    'page' => $page,
                    'size' => self::PAGE_SIZE,
                ],
            ]);

            $status = $response->getStatusCode();
            if (429 === $status) {
                $this->logger->warning('AlloApiClient: rate-limited (429)', ['page' => $page]);

                return null;
            }
            if ($status >= 400) {
                $this->logger->warning('AlloApiClient: API error', ['page' => $page, 'status' => $status]);

                return null;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = $response->toArray(false);

            return $decoded;
        } catch (ExceptionInterface $e) {
            $this->logger->warning('AlloApiClient: request failed', ['page' => $page, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ?Call
    {
        $id = $row['id'] ?? null;
        $startDate = $row['start_date'] ?? null;
        $type = $row['type'] ?? null;
        $length = $row['length_in_minutes'] ?? 0;
        $result = $row['result'] ?? null;

        if (!\is_string($id) || !\is_string($startDate) || !\is_string($type)) {
            return null;
        }

        try {
            // Allo serializes start_date without a trailing 'Z' or offset. We
            // treat it as UTC — for monthly bucketing it makes no difference,
            // and at worst a call near midnight could land in a neighbor month
            // (an acceptable tradeoff vs. introducing a timezone setting).
            $startedAt = new \DateTimeImmutable($startDate, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return new Call(
            id: $id,
            startedAt: $startedAt,
            type: $type,
            lengthMinutes: \is_numeric($length) ? (float) $length : 0.0,
            result: \is_string($result) ? $result : null,
        );
    }
}
