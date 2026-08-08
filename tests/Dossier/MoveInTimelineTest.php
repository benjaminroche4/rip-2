<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\MoveInTimeline;
use App\Dossier\Domain\ProgressTone;
use PHPUnit\Framework\TestCase;

/**
 * The move-in progress bar warms up (green → amber → red) as the desired
 * move-in date gets closer, and clamps outside the timeframe.
 */
final class MoveInTimelineTest extends TestCase
{
    private const START = '2026-01-01 00:00:00';
    private const TARGET = '2026-01-31 00:00:00';

    public function testFreshDossierIsGreen(): void
    {
        $timeline = $this->at('2026-01-04 00:00:00');

        self::assertSame(10, $timeline->percent);
        self::assertSame(ProgressTone::OnTrack, $timeline->tone);
        self::assertFalse($timeline->overdue);
        self::assertSame(27, $timeline->days);
    }

    public function testApproachingDeadlineTurnsAmber(): void
    {
        $timeline = $this->at('2026-01-22 00:00:00');

        self::assertSame(70, $timeline->percent);
        self::assertSame(ProgressTone::Approaching, $timeline->tone);
    }

    public function testImminentDeadlineTurnsRed(): void
    {
        $timeline = $this->at('2026-01-28 12:00:00');

        self::assertSame(92, $timeline->percent);
        self::assertSame(ProgressTone::Critical, $timeline->tone);
    }

    public function testOverdueClampsAtFullRed(): void
    {
        $timeline = $this->at('2026-03-15 00:00:00');

        self::assertSame(100, $timeline->percent);
        self::assertSame(ProgressTone::Critical, $timeline->tone);
        self::assertTrue($timeline->overdue);
        self::assertSame(43, $timeline->days);
    }

    public function testFutureStartClampsAtZero(): void
    {
        $timeline = $this->at('2025-12-25 00:00:00');

        self::assertSame(0, $timeline->percent);
        self::assertSame(ProgressTone::OnTrack, $timeline->tone);
    }

    public function testTargetBeforeStartDoesNotDivideByZero(): void
    {
        $timeline = MoveInTimeline::fromDates(
            new \DateTimeImmutable(self::TARGET),
            new \DateTimeImmutable(self::START),
            new \DateTimeImmutable(self::TARGET),
        );

        self::assertSame(0, $timeline->percent);
        self::assertSame(ProgressTone::OnTrack, $timeline->tone);
    }

    private function at(string $now): MoveInTimeline
    {
        return MoveInTimeline::fromDates(
            new \DateTimeImmutable(self::START),
            new \DateTimeImmutable(self::TARGET),
            new \DateTimeImmutable($now),
        );
    }
}
