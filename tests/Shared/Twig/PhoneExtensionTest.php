<?php

namespace App\Tests\Shared\Twig;

use App\Shared\Twig\PhoneExtension;
use PHPUnit\Framework\TestCase;

final class PhoneExtensionTest extends TestCase
{
    private PhoneExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new PhoneExtension();
    }

    public function testItReturnsEmptyStringForNullOrEmpty(): void
    {
        self::assertSame('', $this->extension->format(null));
        self::assertSame('', $this->extension->format(''));
    }

    public function testItFormatsValidE164ToInternationalByDefault(): void
    {
        self::assertSame('+33 6 12 34 56 78', $this->extension->format('+33612345678'));
    }

    public function testItFormatsToNationalWhenAsked(): void
    {
        self::assertSame('06 12 34 56 78', $this->extension->format('+33612345678', 'national'));
    }

    public function testItFormatsToE164WhenAsked(): void
    {
        self::assertSame('+33612345678', $this->extension->format('+33612345678', 'e164'));
    }

    public function testItReturnsOriginalValueForUnparseableInput(): void
    {
        self::assertSame('not-a-phone', $this->extension->format('not-a-phone'));
    }

    public function testItReturnsOriginalValueForUnknownButParseableNumber(): void
    {
        // Parses but is not a valid phone number; the filter must not crash.
        self::assertSame('+12345', $this->extension->format('+12345'));
    }
}
