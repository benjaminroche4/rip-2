<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Entity;

use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
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

    /** Job inside the agency; only meaningful for agency agents. */
    #[ORM\Column(length: 30, nullable: true, enumType: AgencyPosition::class)]
    private ?AgencyPosition $position = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

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
