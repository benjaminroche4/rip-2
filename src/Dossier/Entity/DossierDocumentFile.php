<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One uploaded file for a requested piece. The binary lives on disk under
 * storage/dossiers/<reference>/ (outside public/), served only through
 * authenticated controllers; storedName is the UUID-based name on disk and
 * originalName is display-only (never used as a path).
 */
#[ORM\Entity]
#[ORM\Table(name: 'dossier_document_file')]
class DossierDocumentFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierDocument::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DossierDocument $document = null;

    #[ORM\Column(length: 64)]
    private ?string $storedName = null;

    #[ORM\Column(length: 255)]
    private ?string $originalName = null;

    #[ORM\Column(length: 100)]
    private ?string $mimeType = null;

    #[ORM\Column]
    private ?int $size = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $uploadedAt = null;

    /** Person of the dossier who deposited the file (cross-deposit allowed). */
    #[ORM\ManyToOne(targetEntity: DossierPerson::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DossierPerson $uploadedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?DossierDocument
    {
        return $this->document;
    }

    public function setDocument(?DossierDocument $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getStoredName(): ?string
    {
        return $this->storedName;
    }

    public function setStoredName(?string $storedName): static
    {
        $this->storedName = $storedName;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?\DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    public function getUploadedBy(): ?DossierPerson
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?DossierPerson $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }
}
