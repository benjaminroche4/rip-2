<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\EmailVerificationRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pending email-verification challenge for a user during registration.
 *
 * Split out from the User entity (was stored as nullable columns there) so that
 * transient OTP state — attempt counts, last-attempt timestamps, eventual audit
 * fields — does not pollute the long-lived profile row, mirroring how
 * {@see ResetPasswordRequest} is already modelled for the password-reset flow.
 *
 * Lifecycle: one active row per user at most (enforced by the unique index on
 * user_id). Issuing a new code removes any existing one for the same user; a
 * successful verify (or attempts exceeding the cap) removes the row.
 */
#[ORM\Entity(repositoryClass: EmailVerificationRequestRepository::class)]
#[ORM\Table(name: 'email_verification_request')]
class EmailVerificationRequest
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private User $user;

    /** Argon2id hash of the 6-digit OTP. */
    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    public function __construct(
        User $user,
        string $codeHash,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->user = $user;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function recordAttempt(\DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = $now;
    }

    public function hasReachedMaxAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
