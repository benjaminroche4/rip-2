<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use App\Dossier\Repository\DossierEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable audit trail entry on a dossier, rendered inside the follow-up
 * thread between the manual notes: pieces requested/deposited/reviewed,
 * files deleted, persons added/removed, manager changes... The kind is a
 * discriminator resolved to a sentence at render time, the payload carries
 * the placeholders (piece label keys, names, reasons). Author snapshot has
 * no FK (same rationale as DossierNote); a null author is a tenant/system
 * action.
 */
#[ORM\Entity(repositoryClass: DossierEventRepository::class)]
#[ORM\Table(name: 'dossier_event')]
class DossierEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    #[ORM\Column(length: 40)]
    private string $kind = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

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

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function setDossier(?Dossier $dossier): static
    {
        $this->dossier = $dossier;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

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
