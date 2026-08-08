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

    /** Progress of the guarantee: not_started, in_progress, obtained, refused. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $guarantorStatus = null;

    /** Number of occupants of the future home. */
    #[ORM\Column(nullable: true)]
    private ?int $occupants = null;

    /** Required amenities, CSV of PropertyListing Amenity values. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $equipment = null;

    /** "yes" / "no", optional (rare but real cases). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $pets = null;

    /**
     * Household typology of the project ("alone", "couple", "family",
     * "flatshare", "other"), optional.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $householdType = null;

    /** "yes" / "no", optional: willing to move in before the desired date. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $earlyMoveIn = null;

    /**
     * Desired lease types, CSV multi-choice ("mobility,furnished", ...),
     * optional.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leaseTypes = null;

    /** Minimum surface in m², optional. */
    #[ORM\Column(nullable: true)]
    private ?int $minSurface = null;

    /** Minimum number of bedrooms (4 = "4+"), optional. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $minBedrooms = null;

    /** "yes" / "no", optional: elevator required. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $elevator = null;

    /** "yes" (accepted) / "no" (excluded), optional: ground floor. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $groundFloor = null;

    /** "yes" (accepted) / "no" (excluded), optional: top floor. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $topFloor = null;

    /** "yes" / "no", optional: parking or box required. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $parking = null;

    /**
     * Up to 3 addresses that matter to the tenant (work, school, ...), each
     * as {address: string, type: string}. Optional.
     *
     * @var list<array{address: string, type: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $importantAddresses = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Every structured criterion filled (the free-form note stays optional).
     * Gates the dossier modules: they unlock only on a complete search.
     */
    public function isComplete(): bool
    {
        return null !== $this->budget
            && '' !== trim((string) $this->areas)
            && null !== $this->moveInAt
            && '' !== trim((string) $this->propertyType)
            && '' !== trim((string) $this->stayDuration)
            && '' !== trim((string) $this->furnishing)
            && '' !== trim((string) $this->guarantorType);
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

    public function getGuarantorStatus(): ?string
    {
        return $this->guarantorStatus;
    }

    public function setGuarantorStatus(?string $guarantorStatus): static
    {
        $this->guarantorStatus = $guarantorStatus;

        return $this;
    }

    public function getOccupants(): ?int
    {
        return $this->occupants;
    }

    public function setOccupants(?int $occupants): static
    {
        $this->occupants = $occupants;

        return $this;
    }

    public function getEquipment(): ?string
    {
        return $this->equipment;
    }

    public function setEquipment(?string $equipment): static
    {
        $this->equipment = $equipment;

        return $this;
    }

    public function getPets(): ?string
    {
        return $this->pets;
    }

    public function setPets(?string $pets): static
    {
        $this->pets = $pets;

        return $this;
    }

    public function getHouseholdType(): ?string
    {
        return $this->householdType;
    }

    public function setHouseholdType(?string $householdType): static
    {
        $this->householdType = $householdType;

        return $this;
    }

    public function getLeaseTypes(): ?string
    {
        return $this->leaseTypes;
    }

    public function setLeaseTypes(?string $leaseTypes): static
    {
        $this->leaseTypes = $leaseTypes;

        return $this;
    }

    public function getMinSurface(): ?int
    {
        return $this->minSurface;
    }

    public function setMinSurface(?int $minSurface): static
    {
        $this->minSurface = $minSurface;

        return $this;
    }

    public function getMinBedrooms(): ?int
    {
        return $this->minBedrooms;
    }

    public function setMinBedrooms(?int $minBedrooms): static
    {
        $this->minBedrooms = $minBedrooms;

        return $this;
    }

    public function getElevator(): ?string
    {
        return $this->elevator;
    }

    public function getGroundFloor(): ?string
    {
        return $this->groundFloor;
    }

    public function setGroundFloor(?string $groundFloor): static
    {
        $this->groundFloor = $groundFloor;

        return $this;
    }

    public function setElevator(?string $elevator): static
    {
        $this->elevator = $elevator;

        return $this;
    }

    public function getTopFloor(): ?string
    {
        return $this->topFloor;
    }

    public function setTopFloor(?string $topFloor): static
    {
        $this->topFloor = $topFloor;

        return $this;
    }

    public function getParking(): ?string
    {
        return $this->parking;
    }

    public function setParking(?string $parking): static
    {
        $this->parking = $parking;

        return $this;
    }

    public function getEarlyMoveIn(): ?string
    {
        return $this->earlyMoveIn;
    }

    public function setEarlyMoveIn(?string $earlyMoveIn): static
    {
        $this->earlyMoveIn = $earlyMoveIn;

        return $this;
    }

    /**
     * @return list<array{address: string, type: string}>
     */
    public function getImportantAddresses(): array
    {
        return $this->importantAddresses ?? [];
    }

    /**
     * @param list<array{address: string, type: string, lat?: float, lng?: float}>|null $importantAddresses
     */
    public function setImportantAddresses(?array $importantAddresses): static
    {
        $this->importantAddresses = [] !== $importantAddresses ? $importantAddresses : null;

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
