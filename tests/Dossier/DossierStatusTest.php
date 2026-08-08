<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierStatus;
use PHPUnit\Framework\TestCase;

/**
 * Semi-automatic lifecycle rules: closure always wins, then pending pieces,
 * then the operator's manual pick.
 */
final class DossierStatusTest extends TestCase
{
    public function testClosureOverridesEverything(): void
    {
        self::assertSame(
            DossierStatus::Closed,
            DossierStatus::effective(DossierStatus::PropertyFound, closed: true, hasPendingDocuments: true),
        );
    }

    public function testPendingDocumentsOverrideTheManualPick(): void
    {
        self::assertSame(
            DossierStatus::AwaitingDocuments,
            DossierStatus::effective(DossierStatus::Searching, closed: false, hasPendingDocuments: true),
        );
    }

    public function testManualPickAppliesWhenNothingIsDerived(): void
    {
        self::assertSame(
            DossierStatus::Searching,
            DossierStatus::effective(DossierStatus::Searching, closed: false, hasPendingDocuments: false),
        );
    }

    public function testOnlyThePipelineStatusesAreManual(): void
    {
        self::assertSame(
            [DossierStatus::New, DossierStatus::Searching, DossierStatus::PropertyFound],
            DossierStatus::manualCases(),
        );
        self::assertFalse(DossierStatus::AwaitingDocuments->isManual());
        self::assertFalse(DossierStatus::Closed->isManual());
    }
}
