<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Service\AdminDateFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for AdminDateFormatter. Verifies the locale-aware label
 * shapes used across the admin charts so a change in IntlDateFormatter
 * pattern keeps the dashboard and payments pages typographically aligned.
 */
final class AdminDateFormatterTest extends TestCase
{
    private AdminDateFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new AdminDateFormatter();
    }

    public function testTodayCapitalizesTheFirstLetter(): void
    {
        $date = new \DateTimeImmutable('2026-05-09'); // ISO Saturday
        $label = $this->formatter->today($date, 'fr');

        self::assertSame('S', $label[0]);
        self::assertStringContainsString('2026', $label);
    }

    public function testMonthLabelIsMonthYear(): void
    {
        $date = new \DateTimeImmutable('2026-05-09');

        self::assertSame('Mai 2026', $this->formatter->monthLabel($date, 'fr'));
        self::assertSame('May 2026', $this->formatter->monthLabel($date, 'en'));
    }

    public function testMonthNameIsCapitalizedMonthOnly(): void
    {
        $date = new \DateTimeImmutable('2026-05-09');

        self::assertSame('Mai', $this->formatter->monthName($date, 'fr'));
        self::assertSame('May', $this->formatter->monthName($date, 'en'));
    }

    public function testYmLabelParsesYearMonthString(): void
    {
        self::assertSame('mai 2026', $this->formatter->ymLabel('2026-05', 'fr'));
        self::assertSame('May 2026', $this->formatter->ymLabel('2026-05', 'en'));
    }

    public function testYmLabelReturnsRawInputWhenInvalid(): void
    {
        self::assertSame('not-a-date', $this->formatter->ymLabel('not-a-date', 'fr'));
    }

    public function testDayLabelStripsTrailingDot(): void
    {
        $date = new \DateTimeImmutable('2026-05-09');
        $label = $this->formatter->dayLabel($date, 'fr');

        self::assertStringEndsNotWith('.', $label);
        self::assertStringContainsString('2026', $label);
    }

    public function testWeekdayLabelIsCapitalizedAbbreviation(): void
    {
        $date = new \DateTimeImmutable('2026-05-09'); // Saturday
        $label = $this->formatter->weekdayLabel($date, 'fr');

        self::assertSame('S', $label[0]);
        self::assertStringEndsNotWith('.', $label);
    }

    public function testWeekdayNamesReturnsSevenIsoOrderedDays(): void
    {
        $names = $this->formatter->weekdayNames('fr');

        self::assertCount(7, $names);
        self::assertSame('Lundi', $names[0]);
        self::assertSame('Dimanche', $names[6]);

        $namesEn = $this->formatter->weekdayNames('en');
        self::assertSame('Monday', $namesEn[0]);
        self::assertSame('Sunday', $namesEn[6]);
    }
}
