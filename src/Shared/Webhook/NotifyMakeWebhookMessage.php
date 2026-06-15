<?php

namespace App\Shared\Webhook;

/**
 * Asks the async handler to forward an arbitrary payload to a Make.com webhook.
 */
final readonly class NotifyMakeWebhookMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
        public MakeWebhookTarget $webhook,
    ) {
    }
}
