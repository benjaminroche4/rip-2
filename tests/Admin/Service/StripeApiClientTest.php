<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Service\StripeApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Surface tests for the thin Stripe SDK wrapper. The actual API methods
 * are covered indirectly via StripePaymentRepositoryTest (which mocks
 * this client). Here we only lock the two behaviors that don't require
 * a real Stripe client: the `isConfigured` guard and the eager-fail
 * when callers try to hit the API without a key.
 */
final class StripeApiClientTest extends TestCase
{
    public function testIsConfiguredReturnsFalseForEmptyKey(): void
    {
        $client = new StripeApiClient('');

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredReturnsTrueForNonEmptyKey(): void
    {
        $client = new StripeApiClient('sk_test_fake_value');

        self::assertTrue($client->isConfigured());
    }

    public function testListPaymentIntentsThrowsWhenKeyIsMissing(): void
    {
        $client = new StripeApiClient('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe API key is not configured.');

        // iterable<...> is lazy — calling iterator_to_array forces the
        // underlying getClient() call that should explode.
        iterator_to_array($client->listPaymentIntents(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('now')));
    }

    public function testRecentPaymentIntentsThrowsWhenKeyIsMissing(): void
    {
        $client = new StripeApiClient('');

        $this->expectException(\RuntimeException::class);

        $client->recentPaymentIntents(10);
    }
}
