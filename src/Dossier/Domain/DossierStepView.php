<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * One step of the path as the tab bar needs it: rank, labels, and where it
 * stands (validated / open / locked behind another step).
 */
final readonly class DossierStepView
{
    public function __construct(
        public string $key,
        public int $rank,
        public string $labelKey,
        public string $icon,
        public bool $validated,
        public bool $unlocked,
        /** Label of the step to validate first; null when the step is open. */
        public ?string $blockedByLabelKey = null,
        /** What validates this step, shown on the locked tab. */
        public ?string $todoKey = null,
        public ?int $count = null,
    ) {
    }

    public static function fromStep(DossierStep $step, DossierProgress $progress, ?int $count = null): self
    {
        return new self(
            key: $step->value,
            rank: $step->rank(),
            labelKey: $step->labelKey(),
            icon: $step->icon(),
            validated: $progress->isValidated($step),
            unlocked: $progress->isUnlocked($step),
            blockedByLabelKey: $progress->blockedBy($step)?->labelKey(),
            todoKey: $step->todoKey(),
            count: $count,
        );
    }
}
