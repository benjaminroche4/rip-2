<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One piece requested from a dossier person (identity, payslips, ...).
 * Created by the file module's request flow; files are deposited on the
 * public deposit page (pairing code + email) or received by email.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dossier_document')]
#[ORM\UniqueConstraint(name: 'uniq_person_document_type', columns: ['person_id', 'type'])]
class DossierDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierPerson::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DossierPerson $person = null;

    #[ORM\Column(length: 30, enumType: DossierDocumentType::class)]
    private ?DossierDocumentType $type = null;

    #[ORM\Column(length: 20, enumType: DossierDocumentStatus::class)]
    private DossierDocumentStatus $status = DossierDocumentStatus::Requested;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    /** Optional reason shown to the tenant when the piece is refused. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $refusalReason = null;

    /**
     * Optional precision under the piece name, written by the manager and
     * shown on the public deposit page ("3 derniers mois", "recto-verso").
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, DossierDocumentFile> */
    #[ORM\OneToMany(targetEntity: DossierDocumentFile::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['uploadedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $files;

    public function __construct()
    {
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): ?DossierPerson
    {
        return $this->person;
    }

    public function setPerson(?DossierPerson $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getType(): ?DossierDocumentType
    {
        return $this->type;
    }

    public function setType(?DossierDocumentType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): DossierDocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DossierDocumentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRequestedAt(): ?\DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(?\DateTimeImmutable $requestedAt): static
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(?\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    public function setRefusalReason(?string $refusalReason): static
    {
        $this->refusalReason = $refusalReason;

        return $this;
    }

    /**
     * @return Collection<int, DossierDocumentFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(DossierDocumentFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setDocument($this);
        }

        return $this;
    }

    public function removeFile(DossierDocumentFile $file): static
    {
        if ($this->files->removeElement($file)) {
            $file->setDocument(null);
        }

        return $this;
    }
}
