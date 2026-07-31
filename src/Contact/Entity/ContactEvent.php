<?php

declare(strict_types=1);

namespace App\Contact\Entity;

use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactStatus;
use App\Contact\Repository\ContactEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable audit trail entry on a contact submission: a status change or a
 * closure-reason change, with an author snapshot (same no-FK rationale as
 * ContactNote). Rendered inside the follow-up thread.
 */
#[ORM\Entity(repositoryClass: ContactEventRepository::class)]
class ContactEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ContactStatus::class)]
    private ?ContactStatus $status = null;

    #[ORM\Column(length: 30, nullable: true, enumType: ClosureReason::class)]
    private ?ClosureReason $closureReason = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorAvatar = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(Contact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getStatus(): ?ContactStatus
    {
        return $this->status;
    }

    public function setStatus(?ContactStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getClosureReason(): ?ClosureReason
    {
        return $this->closureReason;
    }

    public function setClosureReason(?ClosureReason $closureReason): static
    {
        $this->closureReason = $closureReason;

        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): static
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getAuthorAvatar(): ?string
    {
        return $this->authorAvatar;
    }

    public function setAuthorAvatar(?string $authorAvatar): static
    {
        $this->authorAvatar = $authorAvatar;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
