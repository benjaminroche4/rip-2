<?php

namespace App\Shared\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pushes contact requests to the Dashboard backoffice, signed with a shared
 * secret (HMAC-SHA256 of the raw JSON body in the X-Signature header).
 * Failure is non-fatal: we log and swallow so the visitor's flow keeps going.
 */
final class DashboardWebhookClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'DASHBOARD_WEBHOOK_URL')]
        private readonly string $webhookUrl,
        #[Autowire(env: 'DASHBOARD_WEBHOOK_SECRET')]
        private readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function notify(array $payload): bool
    {
        if ('' === $this->webhookUrl || '' === $this->secret) {
            return false;
        }

        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->http->request('POST', $this->webhookUrl, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Signature' => self::sign($body, $this->secret),
                ],
                'timeout' => 3,
                'max_duration' => 5,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300) {
                $this->logger->warning('Dashboard webhook rejected: HTTP '.$status, [
                    'reference' => $payload['reference'] ?? null,
                    'response' => $response->getContent(false),
                ]);

                return false;
            }

            return true;
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Dashboard webhook failed: '.$e->getMessage(), [
                'reference' => $payload['reference'] ?? null,
            ]);

            return false;
        }
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
