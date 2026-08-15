<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\AffordableRent;
use App\Dossier\Domain\CsvSelection;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\LeaseCompatibility;
use App\Dossier\Entity\DossierPerson;
use PHPUnit\Framework\TestCase;

/** Small pure rules extracted from the search card. */
final class SearchDomainRulesTest extends TestCase
{
    public function testCsvSelectionParsesAndTogglesValues(): void
    {
        self::assertSame([], CsvSelection::values(null));
        self::assertSame(['a', 'b'], CsvSelection::values('a,,b'));
        self::assertSame('a,b', CsvSelection::toggle('a', 'b'));
        self::assertSame('b', CsvSelection::toggle('a,b', 'a'));
        self::assertSame('', CsvSelection::toggle('a', 'a'));
    }

    public function testLeaseCompatibilityFlagsMobilityWithALongStay(): void
    {
        self::assertSame(
            'admin.dossiers.show.search.leaseMismatch.mobilityLong',
            LeaseCompatibility::mismatchKey('civil_code,mobility', 'long'),
        );
    }

    public function testLeaseCompatibilityFlagsAlurWithAShortStay(): void
    {
        self::assertSame(
            'admin.dossiers.show.search.leaseMismatch.alurShort',
            LeaseCompatibility::mismatchKey('alur', 'short'),
        );
    }

    public function testLeaseCompatibilityStaysSilentWhenConsistent(): void
    {
        self::assertNull(LeaseCompatibility::mismatchKey('alur', 'long'));
        self::assertNull(LeaseCompatibility::mismatchKey(null, null));
    }

    public function testAffordableRentSumsTenantIncomesAndAppliesTheThreeTimesRule(): void
    {
        $tenant1 = (new DossierPerson())->setRole(DossierPersonRole::TENANT)->setMonthlyIncome(3000);
        $tenant2 = (new DossierPerson())->setRole(DossierPersonRole::TENANT)->setMonthlyIncome(1600);
        $followUp = (new DossierPerson())->setRole(DossierPersonRole::FOLLOW_UP)->setMonthlyIncome(9000);

        self::assertSame(1533, AffordableRent::maxBudget([$tenant1, $tenant2, $followUp]));
    }

    public function testAffordableRentIsUnknownWithoutTenantIncome(): void
    {
        $tenant = (new DossierPerson())->setRole(DossierPersonRole::TENANT);

        self::assertNull(AffordableRent::maxBudget([$tenant]));
        self::assertNull(AffordableRent::maxBudget([]));
    }
}
