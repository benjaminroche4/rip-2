<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Search criteria of the dossier ("Recherche" module). Seeded from the
 * contact's project fields at conversion time; enum-backed contact values
 * (stay duration, guarantor type) are stored as their raw string values so
 * the Dossier context stays decoupled from Contact's enums until the module
 * defines its own model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dossier_search')]
class DossierSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Dossier::class, inversedBy: 'search')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    #[ORM\Column(nullable: true)]
    private ?int $budget = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $areas = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $moveInAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $propertyType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stayDuration = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $furnishing = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $guarantorType = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

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

    public function getBudget(): ?int
    {
        return $this->budget;
    }

    public function setBudget(?int $budget): static
    {
        $this->budget = $budget;

        return $this;
    }

    public function getAreas(): ?string
    {
        return $this->areas;
    }

    public function setAreas(?string $areas): static
    {
        $this->areas = $areas;

        return $this;
    }

    public function getMoveInAt(): ?\DateTimeImmutable
    {
        return $this->moveInAt;
    }

    public function setMoveInAt(?\DateTimeImmutable $moveInAt): static
    {
        $this->moveInAt = $moveInAt;

        return $this;
    }

    public function getPropertyType(): ?string
    {
        return $this->propertyType;
    }

    public function setPropertyType(?string $propertyType): static
    {
        $this->propertyType = $propertyType;

        return $this;
    }

    public function getStayDuration(): ?string
    {
        return $this->stayDuration;
    }

    public function setStayDuration(?string $stayDuration): static
    {
        $this->stayDuration = $stayDuration;

        return $this;
    }

    public function getFurnishing(): ?string
    {
        return $this->furnishing;
    }

    public function setFurnishing(?string $furnishing): static
    {
        $this->furnishing = $furnishing;

        return $this;
    }

    public function getGuarantorType(): ?string
    {
        return $this->guarantorType;
    }

    public function setGuarantorType(?string $guarantorType): static
    {
        $this->guarantorType = $guarantorType;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }
}
