<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\SearchAutosave;
use App\Dossier\Entity\DossierSearch;
use PHPUnit\Framework\TestCase;

final class SearchAutosaveTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-08-15');
    }

    public function testItParsesNumericFieldsAndClampsNegativesToZero(): void
    {
        $autosave = SearchAutosave::fromRaw(' 1800 ', '', ' -5 ', '', '', $this->today);

        self::assertSame(1800, $autosave->budget);
        self::assertSame(0, $autosave->minSurface);
    }

    public function testItTurnsNonNumericAndBlankInputsIntoNull(): void
    {
        $autosave = SearchAutosave::fromRaw('abc', '', '  ', '', '', $this->today);

        self::assertNull($autosave->budget);
        self::assertNull($autosave->minSurface);
        self::assertNull($autosave->moveInAt);
    }

    public function testItKeepsAFutureMoveInDateAndDropsAPastOne(): void
    {
        $future = SearchAutosave::fromRaw('', '2026-09-01', '', '', '', $this->today);
        self::assertSame('2026-09-01', $future->moveInAtProp());

        $past = SearchAutosave::fromRaw('', '2026-08-01', '', '', '', $this->today);
        self::assertNull($past->moveInAt);
    }

    public function testItDropsAnUnparsableDate(): void
    {
        $autosave = SearchAutosave::fromRaw('', 'not-a-date', '', '', '', $this->today);

        self::assertNull($autosave->moveInAt);
    }

    public function testItWritesNormalizedValuesOnTheSnapshot(): void
    {
        $search = new DossierSearch();
        $autosave = SearchAutosave::fromRaw('2000', '2026-09-01', '30', ' 3e ', 'studio', $this->today);

        $autosave->apply($search);

        self::assertSame(2000, $search->getBudget());
        self::assertSame('3e', $search->getAreas());
        self::assertSame('2026-09-01', $search->getMoveInAt()?->format('Y-m-d'));
        self::assertSame('studio', $search->getPropertyType());
        self::assertSame(30, $search->getMinSurface());
    }

    public function testItWritesNullForEmptiedFields(): void
    {
        $search = (new DossierSearch())
            ->setBudget(1500)
            ->setAreas('paris-1')
            ->setPropertyType('apartment');

        SearchAutosave::fromRaw('', '', '', '  ', '', $this->today)->apply($search);

        self::assertNull($search->getBudget());
        self::assertNull($search->getAreas());
        self::assertNull($search->getPropertyType());
    }

    public function testItWhitelistsAreasAndPropertyTypeTokens(): void
    {
        // Les props writable contournent les toggles : un payload live forgé
        // ne doit ni gonfler les colonnes ni marquer la recherche complète
        // avec des tokens bidons.
        $autosave = SearchAutosave::fromRaw('', '', '', '11e, bogus, 94, 11e,'.str_repeat('x', 300), 'studio,peniche,house,studio', $this->today);

        self::assertSame('11e,94', $autosave->areas);
        self::assertSame('studio,house', $autosave->propertyType);

        $empty = SearchAutosave::fromRaw('', '', '', 'nimporte-quoi', 'peniche', $this->today);
        $search = new DossierSearch();
        $empty->apply($search);
        self::assertNull($search->getAreas());
        self::assertNull($search->getPropertyType());
    }

    public function testItFormatsThePropsBackFromTheParsedValues(): void
    {
        $autosave = SearchAutosave::fromRaw(' 1800 ', '2026-09-01', ' 25 ', '', '', $this->today);

        self::assertSame('1800', $autosave->budgetProp());
        self::assertSame('2026-09-01', $autosave->moveInAtProp());
        self::assertSame('25', $autosave->minSurfaceProp());
    }
}
