<?php

namespace App\Shared\Webhook;

/**
 * Identifies which Make.com webhook a payload is forwarded to.
 * Each case maps to a distinct endpoint URL resolved in MakeWebhookClient.
 */
enum MakeWebhookTarget
{
    case CONTACT;
    case ESTIMATION;
}
