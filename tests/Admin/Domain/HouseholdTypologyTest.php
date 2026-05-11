<?php

declare(strict_types=1);

namespace App\Tests\Admin\Domain;

use App\Admin\Domain\HouseholdTypology;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HouseholdTypology::class)]
final class HouseholdTypologyTest extends TestCase
{
    public function testCoversAllCompositionsRequestedByProduct(): void
    {
        // Locks the enum's identity: any future case removal or rename will
        // surface here before silently breaking the PDF labels.
        $values = array_map(fn (HouseholdTypology $t): string => $t->value, HouseholdTypology::cases());

        self::assertSame(
            [
                'one_tenant',
                'one_tenant_one_guarantor',
                'one_tenant_two_guarantors',
                'two_tenants',
                'two_tenants_one_guarantor',
                'two_tenants_two_guarantors',
            ],
            $values,
        );
    }

    public function testLabelKeyPointsAtTheTypologyTranslationNamespace(): void
    {
        self::assertSame(
            'admin.tools.documents.request.typology.two_tenants',
            HouseholdTypology::TWO_TENANTS->labelKey(),
        );
    }
}
