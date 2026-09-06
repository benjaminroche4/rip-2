<?php

namespace App\Shared\Webhook;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotifyDashboardHandler
{
    public function __construct(
        private readonly DashboardWebhookClient $client,
    ) {
    }

    public function __invoke(NotifyDashboardMessage $message): void
    {
        $this->client->notify($message->payload);
    }
}
