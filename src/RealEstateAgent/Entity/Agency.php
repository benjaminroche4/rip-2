<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Entity;

use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Repository\AgencyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Real-estate agency; several agents of the directory can belong to the
 * same one. Agents without an agency are independent.
 */
#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'agency')]
class Agency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank(message: 'admin.agents.create.agency.notBlank')]
    #[Assert\Length(max: 100, maxMessage: 'admin.agents.create.agency.length')]
    private ?string $name = null;

    /** Brand the agency belongs to ("enseigne"); null = independent shop. */
    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'admin.agencies.create.address.length')]
    private ?string $address = null;

    /** E.164 form, normalised client-side by the phone-input controller. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'admin.agencies.create.phone.length')]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'admin.agencies.create.email.invalid')]
    #[Assert\Length(max: 180, maxMessage: 'admin.agencies.create.email.length')]
    private ?string $email = null;

    /** Normalised to an https:// URL by the setter. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'admin.agencies.create.website.invalid', requireTld: true)]
    #[Assert\Length(max: 255, maxMessage: 'admin.agencies.create.website.length')]
    private ?string $website = null;

    /**
     * Deal types the agency handles (it can do both), stored as the enum
     * backing values; null = not filled in.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $specialties = null;

    /** Team-only free note about the agency (never shown publicly). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'admin.agencies.create.note.length')]
    private ?string $note = null;

    /** Logo (clé bucket agencies/<id>/logo/<uuid>.webp), servi par app_avatar. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFilename = null;

    /** Quartiers favoris (codes ParisDistricts en CSV, ex. "11e,12e,94"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $areas = null;

    /** Coordonnées de l'adresse (Places à la saisie, géocodage en repli). */
    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    /** Null = active; a deactivated agency leaves the pickers but keeps its agents and history. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = '' !== trim((string) $address) ? $address : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = '' !== trim((string) $phone) ? $phone : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = '' !== trim((string) $email) ? $email : null;

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

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    /** Trims and prefixes https:// when no scheme was typed; blank = null. */
    public function setWebsite(?string $website): static
    {
        $website = trim((string) $website);
        if ('' !== $website && !preg_match('~^https?://~i', $website)) {
            $website = 'https://'.$website;
        }
        $this->website = '' !== $website ? $website : null;

        return $this;
    }

    /**
     * @return list<AgentSpecialty>
     */
    public function getSpecialties(): array
    {
        return array_map(AgentSpecialty::from(...), $this->specialties ?? []);
    }

    /**
     * @param list<AgentSpecialty> $specialties
     */
    public function setSpecialties(array $specialties): static
    {
        $values = array_values(array_unique(array_map(
            static fn (AgentSpecialty $specialty): string => $specialty->value,
            $specialties,
        )));
        $this->specialties = [] !== $values ? $values : null;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = '' !== trim((string) $note) ? $note : null;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?\DateTimeImmutable $deactivatedAt): static
    {
        $this->deactivatedAt = $deactivatedAt;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->deactivatedAt;
    }

    public function getLogoFilename(): ?string
    {
        return $this->logoFilename;
    }

    public function setLogoFilename(?string $logoFilename): static
    {
        $this->logoFilename = $logoFilename;

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
}
