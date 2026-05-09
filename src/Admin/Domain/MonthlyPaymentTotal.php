<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Aggregated payment data for a single calendar month, ready to be plotted.
 *
 * Amount is stored in the smallest currency unit (centimes for CHF/EUR) the
 * way Stripe returns it — the formatting layer divides by 100 for display.
 */
final readonly class MonthlyPaymentTotal
{
    public function __construct(
        public string $ym,
        public int $totalAmount,
        public string $currency,
        public int $count,
    ) {
    }
}
