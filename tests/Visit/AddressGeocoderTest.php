<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Visit\Service\AddressGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * AddressGeocoder contract: coordinates on success, null on anything else
 * (missing key, zero results, HTTP failure). It must never throw — a failed
 * geocoding cannot block creating a visit.
 */
final class AddressGeocoderTest extends TestCase
{
    public function testReturnsCoordinatesOnSuccess(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('maps.googleapis.com/maps/api/geocode/json', $url);
            self::assertStringContainsString('region=fr', $url);

            return new MockResponse(json_encode([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 48.8553, 'lng' => 2.3765]]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $point = (new AddressGeocoder($client, 'test-key'))->geocode('12 rue de la Roquette, 75011 Paris');

        self::assertNotNull($point);
        self::assertSame(48.8553, $point->latitude);
        self::assertSame(2.3765, $point->longitude);
    }

    public function testReturnsNullOnZeroResults(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'status' => 'ZERO_RESULTS',
            'results' => [],
        ], \JSON_THROW_ON_ERROR)));

        self::assertNull((new AddressGeocoder($client, 'test-key'))->geocode('nowhere at all'));
    }

    public function testReturnsNullOnHttpError(): void
    {
        $client = new MockHttpClient(new MockResponse('server exploded', ['http_code' => 500]));

        self::assertNull((new AddressGeocoder($client, 'test-key'))->geocode('12 rue de la Roquette'));
    }

    public function testMissingKeyShortCircuitsWithoutAnyRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('No HTTP request may leave when the key is missing.');
        });

        self::assertNull((new AddressGeocoder($client, ''))->geocode('12 rue de la Roquette'));
        // The .env placeholder is a "#..." comment string, treated as absent.
        self::assertNull((new AddressGeocoder($client, '# Get your API key at ...'))->geocode('12 rue de la Roquette'));
        self::assertNull((new AddressGeocoder($client, 'test-key'))->geocode('   '));
    }
}
