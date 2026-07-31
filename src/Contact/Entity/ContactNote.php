<?php

declare(strict_types=1);

namespace App\Contact\Entity;

use App\Contact\Repository\ContactNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Follow-up note on a contact submission, shown as a thread on the admin
 * detail page. The author is stored as an id + display snapshots (no FK)
 * so the thread survives account renames/deletions; ownership checks use
 * the id (see ContactNoteVoter).
 */
#[ORM\Entity(repositoryClass: ContactNoteRepository::class)]
class ContactNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $authorId = 0;

    #[ORM\Column(length: 120)]
    private string $authorName = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorAvatar = null;

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

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

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

    public function getAuthorId(): int
    {
        return $this->authorId;
    }

    public function setAuthorId(int $authorId): static
    {
        $this->authorId = $authorId;

        return $this;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): static
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
}
