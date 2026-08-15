<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\SearchCriterion;
use App\Dossier\Entity\DossierSearch;
use App\Dossier\Service\SearchCriterionToggler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class SearchCriterionTogglerTest extends TestCase
{
    private SearchCriterionToggler $toggler;

    protected function setUp(): void
    {
        $this->toggler = new SearchCriterionToggler();
    }

    public function testItSetsASingleSelectCriterion(): void
    {
        $search = new DossierSearch();

        $this->toggler->toggle($search, SearchCriterion::StayDuration, 'short');

        self::assertSame('short', $search->getStayDuration());
    }

    public function testItTogglesOffTheActiveSingleSelectChip(): void
    {
        $search = (new DossierSearch())->setStayDuration('short');

        $this->toggler->toggle($search, SearchCriterion::StayDuration, 'short');

        self::assertNull($search->getStayDuration());
    }

    public function testItAddsAndRemovesValuesOnAMultiSelectCriterion(): void
    {
        $search = new DossierSearch();

        $this->toggler->toggle($search, SearchCriterion::LeaseTypes, 'alur');
        $this->toggler->toggle($search, SearchCriterion::LeaseTypes, 'mobility');
        self::assertSame('alur,mobility', $search->getLeaseTypes());

        $this->toggler->toggle($search, SearchCriterion::LeaseTypes, 'alur');
        self::assertSame('mobility', $search->getLeaseTypes());
    }

    public function testItStoresNullWhenTheLastMultiSelectValueIsToggledOff(): void
    {
        $search = (new DossierSearch())->setEquipment('washing_machine');

        $this->toggler->toggle($search, SearchCriterion::Equipment, 'washing_machine');

        self::assertNull($search->getEquipment());
    }

    public function testItRejectsAValueOutsideTheWhitelist(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Unknown stay duration "forever".');

        $this->toggler->toggle(new DossierSearch(), SearchCriterion::StayDuration, 'forever');
    }

    public function testItHandlesIntegerCriteriaWithToggleOff(): void
    {
        $search = new DossierSearch();

        $this->toggler->toggle($search, SearchCriterion::Occupants, 3);
        self::assertSame(3, $search->getOccupants());

        $this->toggler->toggle($search, SearchCriterion::Occupants, 3);
        self::assertNull($search->getOccupants());
    }

    public function testItRejectsAnOutOfRangeOccupantsCount(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid occupants count "7".');

        $this->toggler->toggle(new DossierSearch(), SearchCriterion::Occupants, 7);
    }
}
