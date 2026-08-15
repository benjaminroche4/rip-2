<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use PHPUnit\Framework\TestCase;

/**
 * The enum backing values are persisted and their label keys are rendered:
 * both are contracts (DB rows and translation files depend on them).
 */
final class AgentEnumsTest extends TestCase
{
    public function testSpecialtyValuesAndLabelKeysAreStable(): void
    {
        self::assertSame(
            ['location', 'transaction'],
            array_column(AgentSpecialty::cases(), 'value'),
        );

        foreach (AgentSpecialty::cases() as $specialty) {
            self::assertSame('admin.agents.specialty.'.$specialty->value, $specialty->labelKey());
        }
    }

    public function testPositionValuesAndLabelKeysAreStable(): void
    {
        self::assertSame(
            ['assistant', 'consultant_sale', 'consultant_rental', 'partner', 'manager'],
            array_column(AgencyPosition::cases(), 'value'),
        );

        foreach (AgencyPosition::cases() as $position) {
            self::assertSame('admin.agents.position.'.$position->value, $position->labelKey());
        }
    }
}
