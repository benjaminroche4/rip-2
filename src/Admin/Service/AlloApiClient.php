<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Admin\Domain\Call;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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
    private const CACHE_TTL = 900;            // 15 min — admins refreshing fast still hit cache
    private const CACHE_KEY_PREFIX = 'allo.calls.window.';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%allo_api_key%')]
        private string $apiKey,
        #[Autowire('%allo_phone_number%')]
        private string $alloNumber,
    ) {
    }

    /**
     * Fetches every call in [$start, $end). Cached for 15 min keyed by the
     * window so two close-by requests share a single API roundtrip.
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

        $key = self::CACHE_KEY_PREFIX.$start->format('Y-m').'.'.$end->format('Y-m');

        return $this->cache->get($key, function (ItemInterface $item) use ($start, $end): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->paginate($start, $end);
        });
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

            $totalPages = (int) ($payload['data']['metadata']['total_pages'] ?? 0);
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
