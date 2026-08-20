<?php

declare(strict_types=1);

namespace App\Tests\Contact\Service;

use App\Contact\Service\ContactRecapMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The recap email never shows the precise move-in date: the label is
 * derived from the stored date (Paris time). No date or less than a
 * month away = "as soon as possible"; otherwise early/mid/late + month.
 */
final class ContactRecapMailerMoveInLabelTest extends KernelTestCase
{
    private ContactRecapMailer $mailer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mailer = self::getContainer()->get(ContactRecapMailer::class);
    }

    /** A far-future date pinned on a given day of month, always >1 month away. */
    private function futureDate(int $day): \DateTimeImmutable
    {
        $base = new \DateTimeImmutable('first day of +3 months', new \DateTimeZone('Europe/Paris'));

        return $base->setDate((int) $base->format('Y'), (int) $base->format('n'), $day)->setTime(12, 0);
    }

    private function monthLabel(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, new \DateTimeZone('Europe/Paris'), pattern: 'MMMM yyyy');

        return (string) $formatter->format($date);
    }

    public function testMissingDateReadsAsSoonAsPossible(): void
    {
        self::assertSame('le plus tôt possible', $this->mailer->moveInLabel(null, 'fr'));
        self::assertSame('as soon as possible', $this->mailer->moveInLabel(null, 'en'));
    }

    public function testDateLessThanAMonthAwayReadsAsSoonAsPossible(): void
    {
        $soon = new \DateTimeImmutable('+10 days');
        self::assertSame('le plus tôt possible', $this->mailer->moveInLabel($soon, 'fr'));
        self::assertSame('as soon as possible', $this->mailer->moveInLabel($soon, 'en'));

        // A past date never leaks either.
        $past = new \DateTimeImmutable('-2 months');
        self::assertSame('le plus tôt possible', $this->mailer->moveInLabel($past, 'fr'));
    }

    public function testDayBucketsDriveEarlyMidLateWithLocalizedMonth(): void
    {
        // Boundaries: day 10 is still "early", 11 and 20 are "mid", 21 is "late".
        foreach ([1 => 'early', 10 => 'early', 11 => 'mid', 20 => 'mid', 21 => 'late', 28 => 'late'] as $day => $bucket) {
            $date = $this->futureDate($day);

            $fr = $this->mailer->moveInLabel($date, 'fr');
            $frMonth = $this->monthLabel($date, 'fr');
            $expectedFr = match ($bucket) {
                'early' => 'début '.$frMonth,
                'mid' => 'mi-'.$frMonth,
                'late' => 'fin '.$frMonth,
            };
            self::assertSame($expectedFr, $fr, \sprintf('Day %d should read "%s" in French.', $day, $bucket));

            $en = $this->mailer->moveInLabel($date, 'en');
            $enMonth = $this->monthLabel($date, 'en');
            $expectedEn = match ($bucket) {
                'early' => 'early '.$enMonth,
                'mid' => 'mid-'.$enMonth,
                'late' => 'late '.$enMonth,
            };
            self::assertSame($expectedEn, $en, \sprintf('Day %d should read "%s" in English.', $day, $bucket));
        }
    }
}
