<?php

namespace App\Shared\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the Make.com webhook endpoint.
 * Failure is non-fatal: we log and swallow so the calling flow keeps going.
 */
final class MakeWebhookClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAKE_WEBHOOK_URL')]
        private readonly string $webhookUrl,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function notify(array $payload): bool
    {
        if ($this->webhookUrl === '') {
            return false;
        }

        try {
            $this->http->request('POST', $this->webhookUrl, [
                'json' => $payload,
                'timeout' => 3,
                'max_duration' => 5,
            ]);
            return true;
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('Make webhook failed: ' . $e->getMessage(), [
                'payload' => $payload,
            ]);
            return false;
        }
    }
}
