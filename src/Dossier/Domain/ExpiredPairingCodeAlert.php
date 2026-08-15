<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * One line of the back-office "deposit code expired" alert: an open dossier
 * whose pairing code aged out while pieces are still awaiting a deposit.
 */
final readonly class ExpiredPairingCodeAlert
{
    public function __construct(
        public string $reference,
        public string $name,
    ) {
    }
}
