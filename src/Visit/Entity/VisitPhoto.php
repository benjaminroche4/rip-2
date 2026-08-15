<?php

declare(strict_types=1);

namespace App\Visit\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Property photo taken during (or for) a visit. The bytes live in the
 * VisitPhotoStorage (disk in dev, private GCS bucket in prod) under
 * visits/<reference>/photos/<uuid>.<ext>; this row only keeps the object
 * path and display metadata.
 */
#[ORM\Entity]
#[ORM\Table(name: 'visit_photo')]
class VisitPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Photos die with their visit. */
    #[ORM\ManyToOne(targetEntity: Visit::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Visit $visit;

    /** Full object path, e.g. visits/VS-087526/photos/<uuid>.webp. */
    #[ORM\Column(length: 255)]
    private string $path;

    /** Client file name, display only — never used as a storage key. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 30)]
    private string $mimeType;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisit(): ?Visit
    {
        return $this->visit;
    }

    public function setVisit(Visit $visit): static
    {
        $this->visit = $visit;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

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
}
