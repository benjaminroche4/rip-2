<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Read model for a status/motif change in the contact follow-up thread.
 */
final readonly class ContactEventItem
{
    public function __construct(
        public int $id,
        public ?ContactStatus $status,
        public ?ClosureReason $closureReason,
        public ?string $kind,
        public ?string $authorName,
        public ?string $authorAvatar,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
