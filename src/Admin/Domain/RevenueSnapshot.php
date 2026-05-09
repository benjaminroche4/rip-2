<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Aggregate of successful (status=succeeded) payments over an arbitrary
 * window. Amount is in the smallest currency unit (centimes); the
 * formatting layer divides by 100 for display.
 */
final readonly class RevenueSnapshot
{
    public function __construct(
        public int $totalAmount,
        public string $currency,
        public int $count,
    ) {
    }
}
