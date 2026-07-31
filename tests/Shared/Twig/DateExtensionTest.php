<?php

namespace App\Tests\Shared\Twig;

use App\Shared\Twig\DateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DateExtensionTest extends TestCase
{
    private DateExtension $extension;

    protected function setUp(): void
    {
        // Stub translator echoing "key|param=value" so assertions can check
        // both the selected key and the injected parameters.
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $pairs = [];
                foreach ($parameters as $k => $v) {
                    $pairs[] = $k.'='.$v;
                }

                return $id.($pairs ? '|'.implode(',', $pairs) : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->extension = new DateExtension($translator);
    }

    public function testTodayUsesTodayAtWithTime(): void
    {
        $today = new \DateTimeImmutable('today 17:04', new \DateTimeZone('Europe/Paris'));

        self::assertSame('date.todayAt|%time%=17:04', $this->extension->humanDate($today));
    }

    public function testYesterdayUsesYesterdayAtWithTime(): void
    {
        $yesterday = new \DateTimeImmutable('yesterday 14:12', new \DateTimeZone('Europe/Paris'));

        self::assertSame('date.yesterdayAt|%time%=14:12', $this->extension->humanDate($yesterday));
    }

    public function testRecentPastUsesDaysAgo(): void
    {
        $threeDaysAgo = new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Paris'))->modify('-3 days');

        self::assertSame('date.daysAgo|%count%=3', $this->extension->humanDate($threeDaysAgo));
    }

    public function testOlderThan30DaysFallsBackToPlainDate(): void
    {
        $old = new \DateTimeImmutable('2020-02-12 10:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('12.02.2020', $this->extension->humanDate($old));
    }

    public function testUtcDatesAreConvertedToParisTimeBeforeBucketing(): void
    {
        // 23:30 UTC yesterday is already "today" in Paris in summer (UTC+2).
        $todayParis = new \DateTimeImmutable('today 01:30', new \DateTimeZone('Europe/Paris'));
        $utc = $todayParis->setTimezone(new \DateTimeZone('UTC'));

        self::assertStringStartsWith('date.todayAt|', $this->extension->humanDate($utc));
    }

    public function testRelativeDaysCoversFutureTodayAndPast(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame('date.inDays|%count%=31', $this->extension->relativeDays(new \DateTimeImmutable('today +31 days', $paris)));
        self::assertSame('date.today', $this->extension->relativeDays(new \DateTimeImmutable('today 23:59', $paris)));
        self::assertSame('date.daysAgo|%count%=3', $this->extension->relativeDays(new \DateTimeImmutable('today -3 days', $paris)));
    }
}
