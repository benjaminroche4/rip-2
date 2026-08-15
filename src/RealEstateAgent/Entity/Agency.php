<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Entity;

use App\RealEstateAgent\Repository\AgencyRepository;
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
}
