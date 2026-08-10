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
