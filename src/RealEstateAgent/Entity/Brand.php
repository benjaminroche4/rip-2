<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Entity;

use App\RealEstateAgent\Repository\BrandRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Agency brand ("enseigne", e.g. Foncia, Century 21): several agencies of
 * the directory can share the same one. Agencies without a brand are
 * independent shops. Created via BrandRepository::findOrCreate from the
 * free-text field of the agency form, so the name is already validated
 * there (length) and unique case-insensitively.
 */
#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\Table(name: 'agency_brand')]
class Brand
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 100, unique: true)]
        private string $name,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
