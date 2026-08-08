<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Progress between the dossier creation and the desired move-in date,
 * displayed as a colored bar on the detail page.
 */
final readonly class MoveInTimeline
{
    private function __construct(
        /** Elapsed share of the timeframe, 0-100. */
        public int $percent,
        public ProgressTone $tone,
        /** Desired move-in date already passed. */
        public bool $overdue,
        /** Full days to the deadline (or since it, when overdue). */
        public int $days,
    ) {
    }

    public static function fromDates(
        \DateTimeImmutable $start,
        \DateTimeImmutable $target,
        \DateTimeImmutable $now,
    ): self {
        $total = max(1, $target->getTimestamp() - $start->getTimestamp());
        $elapsed = $now->getTimestamp() - $start->getTimestamp();
        $percent = (int) round(100 * min(1.0, max(0.0, $elapsed / $total)));

        $secondsToTarget = $target->getTimestamp() - $now->getTimestamp();

        return new self(
            percent: $percent,
            tone: match (true) {
                $percent >= 85 => ProgressTone::Critical,
                $percent >= 60 => ProgressTone::Approaching,
                default => ProgressTone::OnTrack,
            },
            overdue: $secondsToTarget < 0,
            days: intdiv(abs($secondsToTarget), 86400),
        );
    }
}
