<?php

declare(strict_types=1);

namespace App\Visit\Entity;

use App\Dossier\Entity\Dossier;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Repository\VisitRepository;
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

    /** Optional link to the agents directory; survives the agent's deletion. */
    #[ORM\ManyToOne(targetEntity: RealEstateAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RealEstateAgent $agent = null;

    /** Europe/Paris local time (form model timezone). */
    #[ORM\Column]
    #[Assert\NotNull(message: 'admin.visits.create.scheduledAt.notNull')]
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
