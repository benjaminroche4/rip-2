<?php

namespace App\Shared\Webhook;

/**
 * Asks the handler to push a contact request to the Dashboard backoffice.
 */
final readonly class NotifyDashboardMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }
}
