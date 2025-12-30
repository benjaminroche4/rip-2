<?php

namespace App\Entity;

use App\Repository\PropertyEstimationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PropertyEstimationRepository::class)]
class PropertyEstimation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $address = null;

    #[ORM\Column]
    private ?int $surface = null;

    #[ORM\Column(length: 60)]
    private ?string $propertyCondition = null;

    #[ORM\Column]
    private ?int $bedroom = null;

    #[ORM\Column]
    private ?int $bathroom = null;

    #[ORM\Column(length: 60)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255)]
    private ?string $ip = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $lang = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getSurface(): ?int
    {
        return $this->surface;
    }

    public function setSurface(int $surface): static
    {
        $this->surface = $surface;

        return $this;
    }

    public function getPropertyCondition(): ?string
    {
        return $this->propertyCondition;
    }

    public function setPropertyCondition(string $propertyCondition): static
    {
        $this->propertyCondition = $propertyCondition;

        return $this;
    }

    public function getBedroom(): ?int
    {
        return $this->bedroom;
    }

    public function setBedroom(int $bedroom): static
    {
        $this->bedroom = $bedroom;

        return $this;
    }

    public function getBathroom(): ?int
    {
        return $this->bathroom;
    }

    public function setBathroom(int $bathroom): static
    {
        $this->bathroom = $bathroom;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function setLang(?string $lang): static
    {
        $this->lang = $lang;

        return $this;
    }
}
