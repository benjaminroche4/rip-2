<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Entity;

use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Domain\ProfessionalCard;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * External real-estate agent ("agent immobilier") the team works with when
 * hunting properties — a directory entry, not a back-office user.
 */
#[ORM\Entity(repositoryClass: RealEstateAgentRepository::class)]
#[ORM\Table(name: 'real_estate_agent')]
class RealEstateAgent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.agents.create.firstName.notBlank')]
    #[Assert\Length(max: 50, maxMessage: 'admin.agents.create.firstName.length')]
    private ?string $firstName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.agents.create.lastName.notBlank')]
    #[Assert\Length(max: 50, maxMessage: 'admin.agents.create.lastName.length')]
    private ?string $lastName = null;

    /** Agency the agent works for; null = independent agent. */
    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agency $agency = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'admin.agents.create.email.invalid')]
    #[Assert\Length(max: 180, maxMessage: 'admin.agents.create.email.length')]
    private ?string $email = null;

    /** E.164 form, normalised client-side by the phone-input controller. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'admin.agents.create.phone.length')]
    private ?string $phone = null;

    /** Photo (clé bucket agents/<id>/avatar/<uuid>.webp), servie par app_avatar. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarFilename = null;

    /**
     * Deal types the agent handles (an agent can do both), stored as the
     * enum backing values; null = not filled in.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $specialties = null;

    /**
     * Cartes professionnelles loi Hoguet (T, G, S) held by the agent,
     * stored as the enum backing values; null = not filled in.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $professionalCards = null;

    /** Job inside the agency; only meaningful for agency agents. */
    #[ORM\Column(length: 30, nullable: true, enumType: AgencyPosition::class)]
    private ?AgencyPosition $position = null;

    /** Team-only free note about the agent (never shown publicly). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'admin.agents.create.note.length')]
    private ?string $note = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** Null = never edited since creation; set on each edit of the profile. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Snapshot du créateur de la fiche (nom, sinon email) : survit à la suppression du compte staff. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $createdByName = null;

    /** Snapshot de la photo de profil du créateur (clé objet avatar). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $createdByAvatar = null;

    /** Snapshot du dernier modificateur de la fiche. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $updatedByName = null;

    /** Snapshot de la photo de profil du dernier modificateur. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $updatedByAvatar = null;

    /** Null = active; a deactivated agent leaves the pickers but keeps the directory entry and history. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getAgency(): ?Agency
    {
        return $this->agency;
    }

    public function setAgency(?Agency $agency): static
    {
        $this->agency = $agency;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = '' !== trim((string) $phone) ? $phone : null;

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

    /**
     * @return list<ProfessionalCard>
     */
    public function getProfessionalCards(): array
    {
        return array_map(ProfessionalCard::from(...), $this->professionalCards ?? []);
    }

    /**
     * @param list<ProfessionalCard> $cards
     */
    public function setProfessionalCards(array $cards): static
    {
        $values = array_values(array_unique(array_map(
            static fn (ProfessionalCard $card): string => $card->value,
            $cards,
        )));
        $this->professionalCards = [] !== $values ? $values : null;

        return $this;
    }

    public function getPosition(): ?AgencyPosition
    {
        return $this->position;
    }

    public function setPosition(?AgencyPosition $position): static
    {
        $this->position = $position;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getCreatedByName(): ?string
    {
        return $this->createdByName;
    }

    public function setCreatedByName(?string $createdByName): static
    {
        $this->createdByName = $createdByName;

        return $this;
    }

    public function getCreatedByAvatar(): ?string
    {
        return $this->createdByAvatar;
    }

    public function setCreatedByAvatar(?string $createdByAvatar): static
    {
        $this->createdByAvatar = $createdByAvatar;

        return $this;
    }

    public function getUpdatedByAvatar(): ?string
    {
        return $this->updatedByAvatar;
    }

    public function setUpdatedByAvatar(?string $updatedByAvatar): static
    {
        $this->updatedByAvatar = $updatedByAvatar;

        return $this;
    }

    public function getUpdatedByName(): ?string
    {
        return $this->updatedByName;
    }

    public function setUpdatedByName(?string $updatedByName): static
    {
        $this->updatedByName = $updatedByName;

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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = '' !== trim((string) $note) ? $note : null;

        return $this;
    }

    public function getAvatarFilename(): ?string
    {
        return $this->avatarFilename;
    }

    public function setAvatarFilename(?string $avatarFilename): static
    {
        $this->avatarFilename = $avatarFilename;

        return $this;
    }
}
