<?php

namespace App\Auth\Entity;

use App\Auth\Domain\Language;
use App\Auth\Domain\Situation;
use App\Auth\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * Public-facing opaque identifier. Used in admin URLs (and any future
     * public reference) instead of the auto-incremented id, which would
     * leak signup ordering and user count.
     */
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $uniqueId;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    private ?string $firstName = null;

    #[ORM\Column(length: 50)]
    private ?string $lastName = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $phoneNumber = null;

    /**
     * ISO 3166-1 alpha-2 country code (e.g. "FR"). Captured during registration
     * and used for greeting/legal flows that depend on the user's nationality.
     */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 80, enumType: Situation::class, nullable: true)]
    private ?Situation $situation = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 100, nullable: true, unique: true)]
    private ?string $googleId = null;

    /**
     * UUID-based filename of the user's avatar stored under
     * %kernel.project_dir%/var/uploads/avatars/. Served via AvatarController
     * (route /avatars/{filename}) — never exposed in /public/ directly.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $avatarFilename = null;

    #[ORM\Column]
    private bool $isProfileComplete = false;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column(length: 5, enumType: Language::class, nullable: true)]
    private ?Language $language = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->uniqueId = new Ulid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUniqueId(): Ulid
    {
        return $this->uniqueId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = null === $this->password ? null : hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    /**
     * Session invalidation on password change.
     *
     * After {@see __serialize()} the session-side password slot stores a
     * CRC32C of the original hash. When the security layer refreshes the user
     * from DB on every request, it compares the freshly-loaded hash (via this
     * method) to what the session preserved. If they no longer match — typical
     * of a password reset — Symfony invalidates the session immediately, so
     * other devices / attackers already inside the account are kicked out as
     * soon as the next request hits.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        if ($this->getUserIdentifier() !== $user->getUserIdentifier()) {
            return false;
        }

        // Google-only accounts have a NULL password in DB — __serialize() also
        // writes NULL into the session slot, so both sides agree without any
        // CRC32C round-trip. Without this short-circuit the live NULL would
        // get coerced to "" and then hashed, mismatch the stored NULL, and
        // Symfony would invalidate the session on every request (kicking the
        // Google user back to /connexion immediately after a successful OAuth).
        $live = $user->getPassword();
        if (null === $this->password || null === $live) {
            return $this->password === $live;
        }

        // $this->password holds the CRC32C from the session, $user->getPassword()
        // holds the live argon2id hash from DB. Recompute and compare.
        return hash('crc32c', $live) === $this->password;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): static
    {
        $this->nationality = $nationality;

        return $this;
    }

    public function getSituation(): ?Situation
    {
        return $this->situation;
    }

    public function setSituation(?Situation $situation): static
    {
        $this->situation = $situation;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getAvatarFilename(): ?string
    {
        return $this->avatarFilename;
    }

    public function setAvatarFilename(?string $avatarFilename): static
    {
        $this->avatarFilename = $avatarFilename;

        return $this;
    }

    public function isProfileComplete(): bool
    {
        return $this->isProfileComplete;
    }

    public function setProfileComplete(bool $isProfileComplete): static
    {
        $this->isProfileComplete = $isProfileComplete;

        return $this;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

}
