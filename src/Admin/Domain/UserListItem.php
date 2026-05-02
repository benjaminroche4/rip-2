<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Symfony\Component\Uid\Ulid;

/**
 * Read-only projection of a user row for the admin user list. Carries
 * exactly the fields the table needs and nothing else, so the template
 * never sees the Doctrine entity.
 */
final readonly class UserListItem
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public int $id,
        public Ulid $uniqueId,
        public string $slug,
        public string $email,
        public string $firstName,
        public string $lastName,
        public array $roles,
        public ?string $avatarFilename,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * Highest role for display (admin > user). The list of granted roles
     * always contains ROLE_USER as a baseline (User::getRoles), so the
     * "user" branch is the sane fallback.
     */
    public function primaryRole(): string
    {
        return \in_array('ROLE_ADMIN', $this->roles, true) ? 'admin' : 'user';
    }
}
