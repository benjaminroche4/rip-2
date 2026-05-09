<?php

declare(strict_types=1);

namespace App\Admin\Service;

use Stripe\Collection;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin wrapper around the Stripe SDK so callers depend on a small, mockable
 * surface instead of the full StripeClient. Construction is lazy: the SDK
 * client is only instantiated on the first call, which lets the rest of the
 * app boot without an API key in non-prod environments.
 */
class StripeApiClient
{
    private ?StripeClient $client = null;

    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    /**
     * Returns true when an API key is configured. Lets callers degrade
     * gracefully (hide UI, return empty data) rather than throw when the key
     * is intentionally absent (CI, fresh dev clone, dashboard preview).
     */
    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * Lists payment intents created in [$from, $to). Auto-paginates so the
     * caller gets every result without dealing with cursor bookkeeping.
     *
     * @param list<string> $expand Stripe expand paths (e.g. ['data.customer'])
     *
     * @return iterable<PaymentIntent>
     */
    public function listPaymentIntents(\DateTimeImmutable $from, \DateTimeImmutable $to, array $expand = []): iterable
    {
        $params = [
            'created' => [
                'gte' => $from->getTimestamp(),
                'lt' => $to->getTimestamp(),
            ],
            'limit' => 100,
        ];

        if ([] !== $expand) {
            $params['expand'] = $expand;
        }

        /** @var Collection<PaymentIntent> $collection */
        $collection = $this->getClient()->paymentIntents->all($params);

        return $collection->autoPagingIterator();
    }

    /**
     * Returns the $limit most recent payment intents (all statuses), newest
     * first. Single API call (no auto-paging beyond the first page) so the
     * caller can keep this responsive even on large accounts.
     *
     * @param list<string> $expand Stripe expand paths (e.g. ['data.customer'])
     *
     * @return list<PaymentIntent>
     */
    public function recentPaymentIntents(int $limit, array $expand = []): array
    {
        $params = [
            'limit' => max(1, min($limit, 100)),
        ];

        if ([] !== $expand) {
            $params['expand'] = $expand;
        }

        /** @var Collection<PaymentIntent> $collection */
        $collection = $this->getClient()->paymentIntents->all($params);

        return iterator_to_array($collection->getIterator(), false);
    }

    private function getClient(): StripeClient
    {
        if (null !== $this->client) {
            return $this->client;
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe API key is not configured.');
        }

        return $this->client = new StripeClient($this->apiKey);
    }
}
