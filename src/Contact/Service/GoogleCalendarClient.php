<?php

declare(strict_types=1);

namespace App\Contact\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Google Calendar API v3 client for the visio flow, built on a
 * Workspace service account with domain-wide delegation (no heavy SDK,
 * plain HTTP works fine on o2switch). The service account impersonates
 * the agency organizer address; events are created with an auto-generated
 * Google Meet link and patched in place when the visio is rescheduled.
 *
 * Unconfigured (empty env vars) or failing calls return null so callers
 * can fall back to the plain iCalendar invite.
 */
final class GoogleCalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    private const FREEBUSY_URL = 'https://www.googleapis.com/calendar/v3/freeBusy';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    // freebusy.query is not covered by calendar.events: it needs its own
    // granular scope. Requested in a separate JWT grant so a domain-wide
    // delegation limited to calendar.events keeps the event flows working
    // (the freebusy grant then fails alone, and callers fall back).
    private const FREEBUSY_SCOPE = 'https://www.googleapis.com/auth/calendar.freebusy';

    /** Access tokens cached per impersonated subject (one JWT grant each). */
    /** @var array<string, string> */
    private array $accessTokens = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(GOOGLE_CALENDAR_KEY_FILE)%')]
        private readonly string $keyFile,
        #[Autowire('%env(GOOGLE_CALENDAR_IMPERSONATE)%')]
        private readonly string $impersonate,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->keyFile && '' !== $this->impersonate;
    }

    /**
     * Creates (or reschedules) the visio event and returns its id and Meet
     * link; null when the API is unavailable. With $impersonate, the event
     * lives in that subject's primary agenda (the assigned closer) instead
     * of the central organizer's.
     *
     * @param list<string> $attendees
     *
     * @return array{eventId: string, meetLink: ?string}|null
     */
    public function upsertVisioEvent(
        ?string $existingEventId,
        string $summary,
        string $description,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $attendees,
        bool $withMeet = true,
        ?string $impersonate = null,
    ): ?array {
        if (!$this->isConfigured()) {
            return null;
        }

        $paris = new \DateTimeZone('Europe/Paris');
        $payload = [
            'summary' => $summary,
            'description' => $description,
            // Local wall time + explicit timeZone: no offset in the string,
            // so a server misconfigured in UTC cannot shift the slot.
            'start' => ['dateTime' => $start->setTimezone($paris)->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Paris'],
            'end' => ['dateTime' => $end->setTimezone($paris)->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Paris'],
            'attendees' => array_map(static fn (string $email): array => ['email' => $email], $attendees),
        ];

        try {
            // Reschedule first: same event id keeps the Meet link stable.
            if (null !== $existingEventId) {
                $event = $this->request('PATCH', self::CALENDAR_URL.'/'.rawurlencode($existingEventId), $payload, $impersonate);
                if (null !== $event) {
                    $this->logger->info('Google Calendar event patched', ['eventId' => $existingEventId]);

                    return $this->eventResult($event);
                }
                // Event gone (deleted from the agenda): create a fresh one.
            }

            if ($withMeet) {
                $payload['conferenceData'] = [
                    'createRequest' => [
                        'requestId' => Uuid::v4()->toRfc4122(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ];
            }
            $event = $this->request('POST', self::CALENDAR_URL, $payload, $impersonate);
            if (null !== $event) {
                $this->logger->info('Google Calendar event created', ['eventId' => $event['id'] ?? null]);
            }

            return null !== $event ? $this->eventResult($event) : null;
        } catch (\Throwable $e) {
            $this->logger->error('Google Calendar visio event failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generic upsert on the primary agenda of the (optionally) impersonated
     * subject: PATCH when an event id is known (recreate if it vanished),
     * POST otherwise. Returns the raw event resource, or null when the API
     * is unavailable or the call failed (best-effort callers keep going).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    public function upsertEvent(array $payload, ?string $eventId = null, ?string $impersonate = null, string $sendUpdates = 'none'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            if (null !== $eventId) {
                $event = $this->request('PATCH', self::CALENDAR_URL.'/'.rawurlencode($eventId), $payload, $impersonate, $sendUpdates);
                if (null !== $event) {
                    $this->logger->info('Google Calendar event patched', ['eventId' => $eventId, 'subject' => $impersonate ?? $this->impersonate]);

                    return $event;
                }
                // Event gone (deleted from the agenda): create a fresh one.
            }

            $event = $this->request('POST', self::CALENDAR_URL, $payload, $impersonate, $sendUpdates);
            if (null !== $event) {
                $this->logger->info('Google Calendar event created', ['eventId' => $event['id'] ?? null, 'subject' => $impersonate ?? $this->impersonate]);
            }

            return $event;
        } catch (\Throwable $e) {
            $this->logger->error('Google Calendar event upsert failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Reads an event from the (optionally impersonated) subject's primary
     * agenda, e.g. to learn who organizes it before patching or cancelling
     * under the right identity. Best-effort: null on any failure, missing
     * event included; callers fall back to their default identity.
     *
     * @return array<string, mixed>|null
     */
    public function getEvent(string $eventId, ?string $impersonate = null): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->accessToken($impersonate);
            if (null === $token) {
                return null;
            }
            $response = $this->httpClient->request('GET', self::CALENDAR_URL.'/'.rawurlencode($eventId), [
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            /* @var array<string, mixed> */
            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Google Calendar event lookup failed: '.$e->getMessage(), ['eventId' => $eventId]);

            return null;
        }
    }

    /**
     * Busy intervals of the impersonated subject's primary agenda between
     * the two instants. Null when the API is unavailable, unconfigured, or
     * the delegation does not cover the freebusy scope: callers must treat
     * null as "unknown", never as "free".
     *
     * @return list<array{start: string, end: string}>|null
     */
    public function freeBusy(\DateTimeImmutable $start, \DateTimeImmutable $end, ?string $impersonate = null): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->accessToken($impersonate, self::FREEBUSY_SCOPE);
            if (null === $token) {
                return null;
            }

            $utc = new \DateTimeZone('UTC');
            $response = $this->httpClient->request('POST', self::FREEBUSY_URL, [
                'auth_bearer' => $token,
                'json' => [
                    'timeMin' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                    'timeMax' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                    'items' => [['id' => 'primary']],
                ],
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning(\sprintf('Google Calendar freeBusy returned %d', $response->getStatusCode()));

                return null;
            }

            /** @var array{calendars?: array{primary?: array{busy?: list<array{start: string, end: string}>, errors?: list<array<string, string>>}}} $data */
            $data = $response->toArray();
            $primary = $data['calendars']['primary'] ?? null;
            if (null === $primary || [] !== ($primary['errors'] ?? [])) {
                $this->logger->warning('Google Calendar freeBusy could not read the agenda', ['subject' => $impersonate ?? $this->impersonate]);

                return null;
            }

            return $primary['busy'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Google Calendar freeBusy failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Removes the visio event from the agenda (reschedule aborted, lead
     * closed). Missing events are fine: the goal is "not in the agenda".
     *
     * Returns true when the event is gone for sure (deleted, or it no
     * longer existed: 404/410), false on any other failure so callers that
     * store the event id can keep it and retry on their next mutation.
     */
    public function deleteEvent(string $eventId, ?string $impersonate = null, string $sendUpdates = 'none'): bool
    {
        if (!$this->isConfigured()) {
            // Nothing is mirrored when the integration is off.
            return true;
        }

        try {
            $token = $this->accessToken($impersonate);
            if (null === $token) {
                return false;
            }
            $status = $this->httpClient->request('DELETE', self::CALENDAR_URL.'/'.rawurlencode($eventId), [
                'query' => ['sendUpdates' => $sendUpdates],
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ])->getStatusCode();
            if ($status >= 400 && 404 !== $status && 410 !== $status) {
                $this->logger->error(\sprintf('Google Calendar event deletion returned %d', $status), ['eventId' => $eventId]);

                return false;
            }
            $this->logger->info('Google Calendar event deleted', ['eventId' => $eventId]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Google Calendar event deletion failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{eventId: string, meetLink: ?string}
     */
    private function eventResult(array $event): array
    {
        $meetLink = $event['hangoutLink'] ?? null;
        if (null === $meetLink) {
            foreach ($event['conferenceData']['entryPoints'] ?? [] as $entryPoint) {
                if ('video' === ($entryPoint['entryPointType'] ?? null)) {
                    $meetLink = $entryPoint['uri'] ?? null;
                    break;
                }
            }
        }

        return ['eventId' => (string) $event['id'], 'meetLink' => \is_string($meetLink) ? $meetLink : null];
    }

    /**
     * Null strictly means "event gone" (404/410): the PATCH callers then
     * legitimately recreate it. Every other failure (no token, 403, 429,
     * 5xx) throws so it never masquerades as a missing event.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, array $payload, ?string $impersonate = null, string $sendUpdates = 'none'): ?array
    {
        $token = $this->accessToken($impersonate);
        if (null === $token) {
            // No credentials: transient failure, never "event gone" (a null
            // return would make a PATCH caller recreate the event).
            throw new \RuntimeException('Google Calendar access token unavailable.');
        }

        $response = $this->httpClient->request($method, $url, [
            'query' => ['conferenceDataVersion' => '1', 'sendUpdates' => $sendUpdates],
            'auth_bearer' => $token,
            'json' => $payload,
            // Called inside synchronous admin requests: fail fast rather
            // than pinning a shared-host PHP worker for 30s+.
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        $status = $response->getStatusCode();
        if (404 === $status || 410 === $status) {
            // Stale event id: the caller recreates the event.
            return null;
        }
        if ($status >= 400) {
            $this->logger->error(\sprintf('Google Calendar API %s %s returned %d', $method, $url, $status));

            // Transient/auth failure (403, 429, 5xx...): unlike a 404/410,
            // the event may still exist. Throwing (caught by the upsert
            // entry points) aborts the whole upsert so a failed PATCH is
            // never followed by a duplicate POST; the stored ids survive
            // and the next mutation retries against them.
            throw new \RuntimeException(\sprintf('Google Calendar API %s returned %d', $method, $status));
        }

        /* @var array<string, mixed> */
        return $response->toArray();
    }

    /**
     * Service-account JWT grant, impersonating the given domain address
     * (default: the central organizer from the env). Tokens are cached per
     * (subject, scope) pair: syncing one visit touches two agendas.
     */
    private function accessToken(?string $impersonate = null, string $scope = self::SCOPE): ?string
    {
        $subject = $impersonate ?? $this->impersonate;
        $cacheKey = $scope.'|'.$subject;
        if (isset($this->accessTokens[$cacheKey])) {
            return $this->accessTokens[$cacheKey];
        }

        // The env var holds either a path to the key file, or the key JSON
        // itself base64-encoded (handy on hosts where uploading a file
        // outside the webroot is a hassle).
        if (json_validate((string) base64_decode($this->keyFile, true))) {
            $raw = (string) base64_decode($this->keyFile, true);
        } else {
            $raw = @file_get_contents($this->keyFile);
            if (false === $raw) {
                $this->logger->error('Google Calendar key is unreadable (path missing or corrupted base64).');

                return null;
            }
        }
        /** @var array{client_email?: string, private_key?: string} $key */
        $key = json_decode($raw, true) ?: [];
        if (!isset($key['client_email'], $key['private_key'])) {
            $this->logger->error('Google Calendar key file is missing client_email or private_key.');

            return null;
        }

        $now = time();
        $claims = [
            'iss' => $key['client_email'],
            'sub' => $subject,
            'scope' => $scope,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode($claims)),
        ];
        $signature = '';
        if (!openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            $this->logger->error('Google Calendar JWT signing failed.');

            return null;
        }
        $segments[] = $this->base64Url($signature);

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'timeout' => 5,
            'max_duration' => 10,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ],
        ]);
        if ($response->getStatusCode() >= 400) {
            $this->logger->error('Google Calendar token exchange failed with status '.$response->getStatusCode());

            return null;
        }
        $token = $response->toArray()['access_token'] ?? null;
        if (!\is_string($token)) {
            return null;
        }

        return $this->accessTokens[$cacheKey] = $token;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
