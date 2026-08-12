<?php

declare(strict_types=1);

namespace App\Visit\Entity;

use App\Dossier\Entity\Dossier;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Repository\VisitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Property visit scheduled for a dossier: a date, an address, and optionally
 * the real-estate agent (annuaire) who shows the property.
 */
#[ORM\Entity(repositoryClass: VisitRepository::class)]
#[ORM\Table(name: 'visit')]
class Visit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Visits die with their dossier — they make no sense without it. */
    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'admin.visits.create.dossier.notNull')]
    private ?Dossier $dossier = null;

    /** Nature of the visit (first visit, inventory, key handover...). */
    #[ORM\Column(length: 30, enumType: \App\Visit\Domain\VisitType::class)]
    private \App\Visit\Domain\VisitType $type = \App\Visit\Domain\VisitType::PropertyVisit;

    /** Outcome: planned until closed as done / cancelled / no-show. */
    #[ORM\Column(length: 20, enumType: \App\Visit\Domain\VisitStatus::class)]
    private \App\Visit\Domain\VisitStatus $status = \App\Visit\Domain\VisitStatus::Planned;

    /** Listing URL (SeLoger, PAP, agency site...) to review the property. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(message: 'admin.visits.create.listingUrl.invalid', requireTld: true)]
    #[Assert\Length(max: 500, maxMessage: 'admin.visits.create.listingUrl.length')]
    private ?string $listingUrl = null;

    /** Estimated duration in minutes (tour planning, future agenda push). */
    #[ORM\Column(options: ['default' => 30])]
    #[Assert\Choice(choices: [15, 30, 45, 60], message: 'admin.visits.create.duration.invalid')]
    private int $durationMinutes = 30;

    /** The tenant attends, or the agent visits on their behalf (proxy). */
    #[ORM\Column(options: ['default' => true])]
    private bool $clientPresent = true;

    /** Post-visit report, filled once the visit is done. */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $report = null;

    /** Client temperature after a done visit. */
    #[ORM\Column(length: 10, nullable: true, enumType: \App\Visit\Domain\ClientFeeling::class)]
    private ?\App\Visit\Domain\ClientFeeling $clientFeeling = null;

    /** Free note from the operator (access code, contact on site...). */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'admin.visits.create.note.length')]
    private ?string $note = null;

    /**
     * Property photos taken for the visit, oldest first.
     *
     * @var Collection<int, VisitPhoto>
     */
    #[ORM\OneToMany(targetEntity: VisitPhoto::class, mappedBy: 'visit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
    }

    /** Public reference shown in the admin ("VS-087526"). */
    #[ORM\Column(length: 9, unique: true)]
    private ?string $reference = null;

    /** Operator who booked the visit (autofilled at creation). */
    #[ORM\ManyToOne(targetEntity: \App\Auth\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Auth\Entity\User $bookedBy = null;

    /** Team member performing the visit; survives the account's deletion. */
    #[ORM\ManyToOne(targetEntity: \App\Auth\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Auth\Entity\User $assignee = null;

    /** Optional link to the agents directory; survives the agent's deletion. */
    #[ORM\ManyToOne(targetEntity: RealEstateAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RealEstateAgent $agent = null;

    /** Europe/Paris local time (form model timezone). */
    #[ORM\Column]
    #[Assert\NotNull(message: 'admin.visits.create.scheduledAt.notNull')]
    #[Assert\GreaterThanOrEqual('now', message: 'admin.visits.create.scheduledAt.past')]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'admin.visits.create.address.notBlank')]
    #[Assert\Length(max: 255, maxMessage: 'admin.visits.create.address.length')]
    private ?string $address = null;

    /**
     * Coordinates resolved by AddressGeocoder at creation time. Nullable: a
     * failed geocoding never blocks the visit, it just misses from the map.
     */
    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getAgent(): ?RealEstateAgent
    {
        return $this->agent;
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

    public function getType(): \App\Visit\Domain\VisitType
    {
        return $this->type;
    }

    public function setType(\App\Visit\Domain\VisitType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): \App\Visit\Domain\VisitStatus
    {
        return $this->status;
    }

    public function setStatus(\App\Visit\Domain\VisitStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getListingUrl(): ?string
    {
        return $this->listingUrl;
    }

    public function setListingUrl(?string $listingUrl): static
    {
        $this->listingUrl = $listingUrl;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function isClientPresent(): bool
    {
        return $this->clientPresent;
    }

    public function setClientPresent(bool $clientPresent): static
    {
        $this->clientPresent = $clientPresent;

        return $this;
    }

    public function getReport(): ?string
    {
        return $this->report;
    }

    public function setReport(?string $report): static
    {
        $this->report = $report;

        return $this;
    }

    public function getClientFeeling(): ?\App\Visit\Domain\ClientFeeling
    {
        return $this->clientFeeling;
    }

    public function setClientFeeling(?\App\Visit\Domain\ClientFeeling $clientFeeling): static
    {
        $this->clientFeeling = $clientFeeling;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getBookedBy(): ?\App\Auth\Entity\User
    {
        return $this->bookedBy;
    }

    public function setBookedBy(?\App\Auth\Entity\User $bookedBy): static
    {
        $this->bookedBy = $bookedBy;

        return $this;
    }

    public function getAssignee(): ?\App\Auth\Entity\User
    {
        return $this->assignee;
    }

    public function setAssignee(?\App\Auth\Entity\User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function setAgent(?RealEstateAgent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    /**
     * @return Collection<int, VisitPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(VisitPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setVisit($this);
        }

        return $this;
    }

    public function removePhoto(VisitPhoto $photo): static
    {
        $this->photos->removeElement($photo);

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
