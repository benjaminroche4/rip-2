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
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private ?string $accessToken = null;

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
     * link; null when the API is unavailable.
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
                $event = $this->request('PATCH', self::CALENDAR_URL.'/'.rawurlencode($existingEventId), $payload);
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
            $event = $this->request('POST', self::CALENDAR_URL, $payload);
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
     * Removes the visio event from the agenda (reschedule aborted, lead
     * closed). Missing events are fine: the goal is "not in the agenda".
     */
    public function deleteEvent(string $eventId): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $token = $this->accessToken();
            if (null === $token) {
                return;
            }
            $this->httpClient->request('DELETE', self::CALENDAR_URL.'/'.rawurlencode($eventId), [
                'query' => ['sendUpdates' => 'none'],
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ])->getStatusCode();
            $this->logger->info('Google Calendar event deleted', ['eventId' => $eventId]);
        } catch (\Throwable $e) {
            $this->logger->error('Google Calendar event deletion failed: '.$e->getMessage());
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
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, array $payload): ?array
    {
        $token = $this->accessToken();
        if (null === $token) {
            return null;
        }

        $response = $this->httpClient->request($method, $url, [
            'query' => ['conferenceDataVersion' => '1', 'sendUpdates' => 'none'],
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

            return null;
        }

        /* @var array<string, mixed> */
        return $response->toArray();
    }

    /**
     * Service-account JWT grant, impersonating the organizer address.
     */
    private function accessToken(): ?string
    {
        if (null !== $this->accessToken) {
            return $this->accessToken;
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
            'sub' => $this->impersonate,
            'scope' => self::SCOPE,
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

        return $this->accessToken = \is_string($token) ? $token : null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
