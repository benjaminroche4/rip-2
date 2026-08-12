<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Auth\Domain\Language;
use Symfony\Component\Uid\Ulid;

/**
 * Read-only projection of a single user for the admin profile page.
 * Carries the identity + a few signals (auth method, language, profile
 * completion) — never the Doctrine entity, never the password hash.
 */
final readonly class UserProfile
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
        public bool $hasGoogleAuth,
        public bool $hasPasswordAuth,
        public ?Language $language,
        public bool $isProfileComplete,
        /** Two-factor authentication (TOTP) enabled on the account. */
        public bool $hasTwoFactor = false,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * Highest role for display (admin > staff > user), mirroring
     * UserListItem::primaryRole() so the profile badge matches the list.
     */
    public function primaryRole(): string
    {
        if (\in_array('ROLE_ADMIN', $this->roles, true)) {
            return 'admin';
        }

        foreach ($this->roles as $role) {
            if ('ROLE_STAFF' === $role || str_starts_with($role, 'ROLE_SECTION_')) {
                return 'staff';
            }
        }

        return 'user';
    }
}
