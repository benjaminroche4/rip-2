<?php

declare(strict_types=1);

namespace App\Tests\Shared\Twig;

use App\Shared\Twig\DateExtension;
use PHPUnit\Framework\TestCase;

final class DateExtensionTest extends TestCase
{
    public function testNextMondayIsAlwaysAFutureMonday(): void
    {
        $nextMonday = (new DateExtension())->getNextMonday();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        self::assertSame('1', $nextMonday->format('N'));
        self::assertGreaterThan($now, $nextMonday);
        self::assertLessThanOrEqual(7, (int) $now->diff($nextMonday)->days);
    }

    public function testFormattedNextMondayIsLocalizedAndCapitalized(): void
    {
        $extension = new DateExtension();

        self::assertStringStartsWith('Lundi ', $extension->formatNextMonday('fr'));
        self::assertStringStartsWith('Monday, ', $extension->formatNextMonday('en'));
    }
}
