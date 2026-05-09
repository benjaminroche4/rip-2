<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * One row in the recent-payments table on the Payments admin page.
 *
 * Amount is stored in the smallest currency unit (centimes) the way Stripe
 * returns it. Status is the raw Stripe payment intent status, normalized
 * to a known set by the repository before reaching the template.
 */
final readonly class PaymentRow
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $customerName,
        public ?string $customerEmail,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
