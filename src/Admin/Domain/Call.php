<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * DTO representing a single phone call retrieved from the Allo API.
 *
 * Aligned with the documented response shape (data.results[]):
 *   id, start_date, type (INBOUND|OUTBOUND), length_in_minutes, result.
 *
 * Stays a pure domain object — repositories produce these, controllers and
 * templates consume them. No Doctrine, no HTTP, no mapping logic in here.
 */
final readonly class Call
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $startedAt,
        public string $type,
        public float $lengthMinutes,
        public ?string $result,
    ) {
    }
}
