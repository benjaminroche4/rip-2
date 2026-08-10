<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * One row of business activity (an assigned lead or a managed dossier) on
 * the admin user profile. Reference + display label only: the template
 * builds the link from the reference.
 */
final readonly class UserActivityItem
{
    public function __construct(
        public string $reference,
        public string $label,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
