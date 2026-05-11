<?php

declare(strict_types=1);

namespace App\Tests\Admin\Entity;

use App\Admin\Entity\Document;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the locale-aware accessors. Plain getters/setters are not
 * tested — they would only assert the behavior of PHP itself.
 */
#[CoversClass(Document::class)]
final class DocumentTest extends TestCase
{
    public function testItReturnsFrenchNameWhenLocaleIsFrench(): void
    {
        $doc = (new Document())->setNameFr('Bail type')->setNameEn('Lease template');

        self::assertSame('Bail type', $doc->getName('fr'));
    }

    public function testItReturnsEnglishNameWhenLocaleIsEnglish(): void
    {
        $doc = (new Document())->setNameFr('Bail type')->setNameEn('Lease template');

        self::assertSame('Lease template', $doc->getName('en'));
    }

    public function testItFallsBackToFrenchNameWhenEnglishIsEmpty(): void
    {
        $doc = (new Document())->setNameFr('Bail type')->setNameEn('');

        self::assertSame('Bail type', $doc->getName('en'));
    }

    public function testItReturnsFrenchNameForUnknownLocale(): void
    {
        $doc = (new Document())->setNameFr('Bail type')->setNameEn('Lease template');

        // Locales other than 'en' fall back to French — guards against
        // a stray locale string (e.g. 'es' someday) returning empty.
        self::assertSame('Bail type', $doc->getName('es'));
    }

    public function testItReturnsFrenchDescriptionWhenLocaleIsFrench(): void
    {
        $doc = (new Document())
            ->setDescriptionFr('Description FR')
            ->setDescriptionEn('Description EN');

        self::assertSame('Description FR', $doc->getDescription('fr'));
    }

    public function testItReturnsEnglishDescriptionWhenLocaleIsEnglish(): void
    {
        $doc = (new Document())
            ->setDescriptionFr('Description FR')
            ->setDescriptionEn('Description EN');

        self::assertSame('Description EN', $doc->getDescription('en'));
    }

    public function testItFallsBackToFrenchDescriptionWhenEnglishIsNull(): void
    {
        $doc = (new Document())
            ->setDescriptionFr('Description FR')
            ->setDescriptionEn(null);

        self::assertSame('Description FR', $doc->getDescription('en'));
    }

    public function testItReturnsNullDescriptionWhenBothAreNull(): void
    {
        $doc = (new Document())
            ->setDescriptionFr(null)
            ->setDescriptionEn(null);

        self::assertNull($doc->getDescription('fr'));
        self::assertNull($doc->getDescription('en'));
    }

    public function testNewDocumentIsNotPinnedByDefault(): void
    {
        // Default must be false so existing flows (admin creates a doc
        // without ticking the box) don't accidentally pin everything.
        self::assertFalse((new Document())->isPinned());
    }

    public function testPinnedFlagIsSettable(): void
    {
        $doc = (new Document())->setPinned(true);
        self::assertTrue($doc->isPinned());

        $doc->setPinned(false);
        self::assertFalse($doc->isPinned());
    }
}
