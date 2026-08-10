<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Visit\Domain\GeoPoint;
use App\Visit\Service\WalkingRoutePlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * WalkingRoutePlanner contract: decoded polyline on success (cached), null
 * on anything else (missing key, fewer than 2 stops, API failure). It must
 * never throw — a routeless map still shows its pins.
 */
final class WalkingRoutePlannerTest extends TestCase
{
    /**
     * @return list<GeoPoint>
     */
    private static function stops(): array
    {
        return [
            new GeoPoint(48.8553, 2.3765),
            new GeoPoint(48.8676, 2.3633),
        ];
    }

    public function testDecodesTheRoutePolyline(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString('routes.googleapis.com/directions/v2:computeRoutes', $url);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('WALK', $body['travelMode']);
            self::assertSame([], $body['intermediates']);
            self::assertEqualsWithDelta(48.8553, $body['origin']['location']['latLng']['latitude'], 1e-9);

            // Google's documented encoded-polyline example.
            return new MockResponse(json_encode([
                'routes' => [['polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@']]],
            ], \JSON_THROW_ON_ERROR));
        });

        $route = (new WalkingRoutePlanner($client, new ArrayAdapter(), 'test-key'))->route(self::stops());

        self::assertSame([
            ['lat' => 38.5, 'lng' => -120.2],
            ['lat' => 40.7, 'lng' => -120.95],
            ['lat' => 43.252, 'lng' => -126.453],
        ], $route);
    }

    public function testMiddleStopsBecomeIntermediates(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(1, $body['intermediates']);
            self::assertEqualsWithDelta(48.86, $body['intermediates'][0]['location']['latLng']['latitude'], 1e-9);
            self::assertEqualsWithDelta(48.8676, $body['destination']['location']['latLng']['latitude'], 1e-9);

            return new MockResponse(json_encode([
                'routes' => [['polyline' => ['encodedPolyline' => '_p~iF~ps|U']]],
            ], \JSON_THROW_ON_ERROR));
        });

        $route = (new WalkingRoutePlanner($client, new ArrayAdapter(), 'test-key'))->route([
            new GeoPoint(48.8553, 2.3765),
            new GeoPoint(48.86, 2.37),
            new GeoPoint(48.8676, 2.3633),
        ]);

        self::assertNotNull($route);
    }

    public function testSuccessfulRouteIsCachedAcrossCalls(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode([
                'routes' => [['polyline' => ['encodedPolyline' => '_p~iF~ps|U']]],
            ], \JSON_THROW_ON_ERROR));
        });

        $planner = new WalkingRoutePlanner($client, new ArrayAdapter(), 'test-key');
        self::assertNotNull($planner->route(self::stops()));
        self::assertNotNull($planner->route(self::stops()));
        self::assertSame(1, $requests, 'The decoded route is served from cache.');
    }

    public function testApiFailureReturnsNullAndIsNotCached(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('denied', ['http_code' => 403]);
        });

        $planner = new WalkingRoutePlanner($client, new ArrayAdapter(), 'test-key');
        self::assertNull($planner->route(self::stops()));
        self::assertNull($planner->route(self::stops()));
        self::assertSame(2, $requests, 'Failures are retried, never pinned in cache.');
    }

    public function testMissingKeyOrSingleStopShortCircuitsWithoutAnyRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('No HTTP request may leave without a key and 2+ stops.');
        });

        $planner = new WalkingRoutePlanner($client, new ArrayAdapter(), '');
        self::assertNull($planner->route(self::stops()));

        $withKey = new WalkingRoutePlanner($client, new ArrayAdapter(), 'test-key');
        self::assertNull($withKey->route([new GeoPoint(48.8553, 2.3765)]));
        self::assertNull($withKey->route([]));
    }
}
