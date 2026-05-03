<?php

namespace App\Tests\Shared\Form\DataTransformer;

use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use PHPUnit\Framework\TestCase;

final class PhoneNumberE164TransformerTest extends TestCase
{
    public function testTransformIsIdentity(): void
    {
        $t = new PhoneNumberE164Transformer();
        self::assertSame('+33612345678', $t->transform('+33612345678'));
        self::assertNull($t->transform(null));
    }

    public function testReverseTransformAcceptsE164(): void
    {
        $t = new PhoneNumberE164Transformer();
        self::assertSame('+33612345678', $t->reverseTransform('+33612345678'));
    }

    public function testReverseTransformCanonicalizesNationalFrenchInput(): void
    {
        $t = new PhoneNumberE164Transformer();
        self::assertSame('+33612345678', $t->reverseTransform('06 12 34 56 78'));
        self::assertSame('+33612345678', $t->reverseTransform('0612345678'));
    }

    public function testReverseTransformAcceptsForeignE164(): void
    {
        $t = new PhoneNumberE164Transformer();
        // Swiss mobile
        self::assertSame('+41791234567', $t->reverseTransform('+41 79 123 45 67'));
    }

    public function testReverseTransformReturnsNullForEmpty(): void
    {
        $t = new PhoneNumberE164Transformer();
        self::assertNull($t->reverseTransform(''));
        self::assertNull($t->reverseTransform('   '));
        self::assertNull($t->reverseTransform(null));
    }

    public function testReverseTransformReturnsNullForGarbageWithoutDigits(): void
    {
        $t = new PhoneNumberE164Transformer();
        // No digits at all — nothing to keep, treat as empty.
        self::assertNull($t->reverseTransform('abc'));
    }

    public function testReverseTransformFallsBackToCleanedDigitsForUnparseable(): void
    {
        $t = new PhoneNumberE164Transformer();
        // "+33 1" parses but is not a valid number — keep what the user typed
        // rather than blocking the form. Admin can clean up later.
        self::assertSame('+331', $t->reverseTransform('+33 1'));
    }

    public function testReverseTransformFallsBackForOversizedNumber(): void
    {
        $t = new PhoneNumberE164Transformer();
        // Way too long to be a valid phone number, but we still accept it.
        $result = $t->reverseTransform('+3333333333333333333333333333');
        self::assertNotNull($result);
        self::assertSame('+', $result[0]);
        self::assertLessThanOrEqual(25, strlen($result));
    }
}
