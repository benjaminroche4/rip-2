<?php

namespace App\MessageHandler;

use App\Message\NotifyMakeWebhookMessage;
use App\Shared\Webhook\MakeWebhookClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotifyMakeWebhookHandler
{
    public function __construct(
        private readonly MakeWebhookClient $client,
    ) {}

    public function __invoke(NotifyMakeWebhookMessage $message): void
    {
        $this->client->notify($message->payload);
    }
}
