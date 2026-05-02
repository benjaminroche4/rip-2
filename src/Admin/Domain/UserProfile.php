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
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function primaryRole(): string
    {
        return \in_array('ROLE_ADMIN', $this->roles, true) ? 'admin' : 'user';
    }
}
