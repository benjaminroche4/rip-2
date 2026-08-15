<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierStatus;
use App\Dossier\Domain\DossierStep;
use PHPUnit\Framework\TestCase;

/**
 * Step-based automatic lifecycle: the status names the step currently
 * pending, "Finalisation" once the visit is done, and closure always wins.
 */
final class DossierStatusTest extends TestCase
{
    public function testStatusNamesThePendingStep(): void
    {
        self::assertSame(DossierStatus::Persons, DossierStatus::fromPendingStep(DossierStep::Persons));
        self::assertSame(DossierStatus::Search, DossierStatus::fromPendingStep(DossierStep::Search));
        self::assertSame(DossierStatus::File, DossierStatus::fromPendingStep(DossierStep::File));
        self::assertSame(DossierStatus::Visit, DossierStatus::fromPendingStep(DossierStep::Visit));
    }

    public function testApartmentFoundMeansFinalization(): void
    {
        // Visite validée : qu'il reste le paiement ou que tout soit validé,
        // le dossier est en finalisation.
        self::assertSame(DossierStatus::Finalization, DossierStatus::fromPendingStep(DossierStep::Payment));
        self::assertSame(DossierStatus::Finalization, DossierStatus::fromPendingStep(null));
    }

    public function testClosureOverridesTheStepStatus(): void
    {
        self::assertSame(DossierStatus::Closed, DossierStatus::effective(DossierStatus::Finalization, closed: true));
        self::assertSame(DossierStatus::Search, DossierStatus::effective(DossierStatus::Search, closed: false));
    }
}
