<?php

namespace App\Tests\Admin;

use App\Admin\Domain\HouseholdTypology;
use PHPUnit\Framework\TestCase;

/**
 * The typology is no longer picked by the operator: it derives from the
 * persons' roles, with out-of-range households clamped to the closest
 * supported composition.
 */
final class HouseholdTypologyTest extends TestCase
{
    public function testDerivesEveryCompositionFromRoleCounts(): void
    {
        self::assertSame(HouseholdTypology::ONE_TENANT, HouseholdTypology::fromCounts(1, 0));
        self::assertSame(HouseholdTypology::ONE_TENANT_ONE_GUARANTOR, HouseholdTypology::fromCounts(1, 1));
        self::assertSame(HouseholdTypology::ONE_TENANT_TWO_GUARANTORS, HouseholdTypology::fromCounts(1, 2));
        self::assertSame(HouseholdTypology::TWO_TENANTS, HouseholdTypology::fromCounts(2, 0));
        self::assertSame(HouseholdTypology::TWO_TENANTS_ONE_GUARANTOR, HouseholdTypology::fromCounts(2, 1));
        self::assertSame(HouseholdTypology::TWO_TENANTS_TWO_GUARANTORS, HouseholdTypology::fromCounts(2, 2));
    }

    public function testExoticHouseholdsClampToTheClosestComposition(): void
    {
        self::assertSame(HouseholdTypology::ONE_TENANT, HouseholdTypology::fromCounts(0, 0), 'No tenant yet still maps to the single-tenant base.');
        self::assertSame(HouseholdTypology::TWO_TENANTS_TWO_GUARANTORS, HouseholdTypology::fromCounts(3, 4));
    }
}
