<?php

declare(strict_types=1);

namespace App\Tests\Contact;

use App\Contact\Service\GoogleCalendarClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleCalendarClientTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        // Throwaway service-account key: real RSA material so the JWT
        // signing path runs for real, nothing leaves the process.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'gcal-test-');
        file_put_contents($this->keyFile, json_encode([
            'client_email' => 'visio@service-account.test',
            'private_key' => $pem,
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
    }

    public function testItStaysSilentWhenUnconfigured(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('No HTTP call expected when the client is unconfigured.');
        });
        $client = new GoogleCalendarClient($http, new NullLogger(), '', '');

        self::assertFalse($client->isConfigured());
        self::assertNull($client->upsertVisioEvent(null, 'Visio', 'desc', new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+1 day 30 minutes'), ['a@b.test']));
        $client->deleteEvent('whatever');
    }

    public function testItCreatesTheEventWithAMeetLink(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }

            return new MockResponse((string) json_encode([
                'id' => 'evt-123',
                'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            ]));
        });
        $client = new GoogleCalendarClient($http, new NullLogger(), $this->keyFile, 'contact@relocation-in-paris.fr');

        $result = $client->upsertVisioEvent(
            null,
            'Visio Jane Doe | Relocation in Paris',
            'Jane Doe / jane@example.com',
            new \DateTimeImmutable('2026-08-12 14:00'),
            new \DateTimeImmutable('2026-08-12 14:30'),
            ['jane@example.com', 'agent@relocation-in-paris.fr'],
        );

        self::assertSame(['eventId' => 'evt-123', 'meetLink' => 'https://meet.google.com/abc-defg-hij'], $result);
        self::assertCount(2, $requests);

        // JWT grant impersonating the organizer address.
        [$method, $url, $options] = $requests[0];
        self::assertSame('POST', $method);
        parse_str((string) $options['body'], $tokenBody);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $tokenBody['grant_type']);
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $tokenBody['assertion'])[1], '-_', '+/')), true);
        self::assertSame('contact@relocation-in-paris.fr', $claims['sub']);
        self::assertSame('visio@service-account.test', $claims['iss']);

        // Event insertion with the Meet conference request and attendees.
        [$method, $url, $options] = $requests[1];
        self::assertSame('POST', $method);
        self::assertStringContainsString('/calendars/primary/events', $url);
        self::assertStringContainsString('conferenceDataVersion=1', $url);
        $payload = json_decode((string) $options['body'], true);
        self::assertSame('hangoutsMeet', $payload['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        self::assertSame([['email' => 'jane@example.com'], ['email' => 'agent@relocation-in-paris.fr']], $payload['attendees']);
        self::assertSame('Europe/Paris', $payload['start']['timeZone']);
        // Paris wall time, no offset: a UTC-configured host must not be
        // able to shift the slot (the admin typed 14:00 Paris time).
        $expected = (new \DateTimeImmutable('2026-08-12 14:00'))
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->format('Y-m-d\TH:i:s');
        self::assertSame($expected, $payload['start']['dateTime']);
        self::assertDoesNotMatchRegularExpression('/[+Z]/', $payload['start']['dateTime']);
    }

    public function testReschedulingPatchesTheExistingEvent(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url];
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }

            return new MockResponse((string) json_encode([
                'id' => 'evt-123',
                'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            ]));
        });
        $client = new GoogleCalendarClient($http, new NullLogger(), $this->keyFile, 'contact@relocation-in-paris.fr');

        $result = $client->upsertVisioEvent('evt-123', 'Visio', 'desc', new \DateTimeImmutable('+2 days'), new \DateTimeImmutable('+2 days 30 minutes'), ['jane@example.com']);

        self::assertSame('evt-123', $result['eventId'] ?? null);
        // Token then PATCH, no POST: the Meet link stays stable.
        self::assertCount(2, $requests);
        self::assertSame('PATCH', $requests[1][0]);
        self::assertStringContainsString('/events/evt-123', $requests[1][1]);
    }

    public function testAStaleEventIdFallsBackToCreation(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }
            if ('PATCH' === $method) {
                // The agent deleted the event from their agenda.
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse((string) json_encode(['id' => 'evt-456', 'hangoutLink' => 'https://meet.google.com/new-link']));
        });
        $client = new GoogleCalendarClient($http, new NullLogger(), $this->keyFile, 'contact@relocation-in-paris.fr');

        $result = $client->upsertVisioEvent('evt-gone', 'Visio', 'desc', new \DateTimeImmutable('+2 days'), new \DateTimeImmutable('+2 days 30 minutes'), ['jane@example.com']);

        self::assertSame('evt-456', $result['eventId'] ?? null);
        self::assertSame(3, $calls, 'token + failed PATCH + POST');
    }
}
