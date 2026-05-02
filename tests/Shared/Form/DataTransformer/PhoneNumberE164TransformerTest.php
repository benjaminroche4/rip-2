<?php

namespace App\Tests\Shared\Form\DataTransformer;

use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

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

    public function testReverseTransformRejectsGarbage(): void
    {
        $t = new PhoneNumberE164Transformer();
        $this->expectException(TransformationFailedException::class);
        $t->reverseTransform('abc');
    }

    public function testReverseTransformRejectsInvalidNumber(): void
    {
        $t = new PhoneNumberE164Transformer();
        $this->expectException(TransformationFailedException::class);
        // Parses but not a valid number
        $t->reverseTransform('+33 1');
    }
}
