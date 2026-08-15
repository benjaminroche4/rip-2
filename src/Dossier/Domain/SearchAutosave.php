<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Dossier\Entity\DossierSearch;

/**
 * Normalized payload of the search card's main autosave: parses the raw
 * input strings (budget, move-in date, min surface, areas, property types)
 * into typed values, writes them on the snapshot, and formats them back so
 * the live props always mirror what was actually persisted.
 */
final readonly class SearchAutosave
{
    private function __construct(
        public ?int $budget,
        public ?\DateTimeImmutable $moveInAt,
        public ?int $minSurface,
        public string $areas,
        public string $propertyType,
    ) {
    }

    public static function fromRaw(
        string $budget,
        string $moveInAt,
        string $minSurface,
        string $areas,
        string $propertyType,
        ?\DateTimeImmutable $today = null,
    ): self {
        $today ??= new \DateTimeImmutable('today');

        $budgetValue = '' !== trim($budget) && is_numeric(trim($budget))
            ? max(0, (int) trim($budget))
            : null;

        $moveInAtValue = '' !== trim($moveInAt)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', trim($moveInAt)) ?: null)
            : null;
        if (null !== $moveInAtValue && $moveInAtValue < $today) {
            // A desired move-in date can only be today or later.
            $moveInAtValue = null;
        }

        $minSurfaceValue = '' !== trim($minSurface) && is_numeric(trim($minSurface))
            ? max(0, (int) trim($minSurface))
            : null;

        return new self($budgetValue, $moveInAtValue, $minSurfaceValue, trim($areas), trim($propertyType));
    }

    public function apply(DossierSearch $search): void
    {
        $search->setBudget($this->budget);
        $search->setAreas('' !== $this->areas ? $this->areas : null);
        $search->setMoveInAt($this->moveInAt);
        $search->setPropertyType('' !== $this->propertyType ? $this->propertyType : null);
        $search->setMinSurface($this->minSurface);
    }

    public function budgetProp(): string
    {
        return null !== $this->budget ? (string) $this->budget : '';
    }

    public function moveInAtProp(): string
    {
        return $this->moveInAt?->format('Y-m-d') ?? '';
    }

    public function minSurfaceProp(): string
    {
        return null !== $this->minSurface ? (string) $this->minSurface : '';
    }
}
