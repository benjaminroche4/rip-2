<?php

declare(strict_types=1);

namespace App\Tests\Contact;

use App\Contact\Domain\ParisDistricts;
use PHPUnit\Framework\TestCase;

final class ParisDistrictsTest extends TestCase
{
    public function testItDetectsThatEveryArrondissementIsSelected(): void
    {
        self::assertTrue(ParisDistricts::allArrondissementsSelected(implode(',', ParisDistricts::ARRONDISSEMENTS)));
    }

    public function testItIgnoresPetiteCouronneAndSpacing(): void
    {
        $areas = ' '.implode(' , ', ParisDistricts::ARRONDISSEMENTS).' , 92';

        self::assertTrue(ParisDistricts::allArrondissementsSelected($areas));
    }

    public function testItIsFalseWhenOneArrondissementIsMissing(): void
    {
        $areas = array_diff(ParisDistricts::ARRONDISSEMENTS, ['7e']);

        self::assertFalse(ParisDistricts::allArrondissementsSelected(implode(',', $areas)));
        self::assertFalse(ParisDistricts::allArrondissementsSelected(''));
        self::assertFalse(ParisDistricts::allArrondissementsSelected('92,93,94'));
    }
}
