<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ImportantAddressList;
use PHPUnit\Framework\TestCase;

final class ImportantAddressListTest extends TestCase
{
    public function testItAppendsATrimmedRowWithoutCoordinates(): void
    {
        $rows = ImportantAddressList::add([], '  12 rue de la Paix  ', 'work');

        self::assertSame([['address' => '12 rue de la Paix', 'type' => 'work']], $rows);
    }

    public function testItKeepsPlausibleCoordinatesRoundedToSixDecimals(): void
    {
        $rows = ImportantAddressList::add([], 'Bureau', 'work', '48.85661234567', '2.35221234567');

        self::assertSame(
            [['address' => 'Bureau', 'type' => 'work', 'lat' => 48.856612, 'lng' => 2.352212]],
            $rows,
        );
    }

    public function testItDropsCoordinatesWhenOneIsInvalidOrOutOfBounds(): void
    {
        self::assertSame(
            [['address' => 'Bureau', 'type' => 'work']],
            ImportantAddressList::add([], 'Bureau', 'work', '91.0', '2.35'),
        );
        self::assertSame(
            [['address' => 'Bureau', 'type' => 'work']],
            ImportantAddressList::add([], 'Bureau', 'work', 'abc', '2.35'),
        );
    }

    public function testItRefusesABlankAddress(): void
    {
        self::assertNull(ImportantAddressList::add([], '   ', 'work'));
    }

    public function testItAcceptsUpToSixRows(): void
    {
        $rows = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $address) {
            $rows = ImportantAddressList::add($rows, $address, 'work');
            self::assertNotNull($rows);
        }

        self::assertCount(6, $rows);
    }

    public function testItRefusesToGrowBeyondTheCap(): void
    {
        $rows = [
            ['address' => 'A', 'type' => 'work'],
            ['address' => 'B', 'type' => 'school'],
            ['address' => 'C', 'type' => 'gym'],
            ['address' => 'D', 'type' => 'daycare'],
            ['address' => 'E', 'type' => 'family'],
            ['address' => 'F', 'type' => 'other'],
        ];

        self::assertNull(ImportantAddressList::add($rows, 'G', 'other'));
    }

    public function testItTruncatesTheAddressAt255Characters(): void
    {
        $rows = ImportantAddressList::add([], str_repeat('a', 300), 'work');

        self::assertNotNull($rows);
        self::assertSame(255, mb_strlen($rows[0]['address']));
    }

    public function testItRemovesARowAndReindexes(): void
    {
        $rows = [
            ['address' => 'A', 'type' => 'work'],
            ['address' => 'B', 'type' => 'school'],
        ];

        self::assertSame([['address' => 'B', 'type' => 'school']], ImportantAddressList::remove($rows, 0));
    }

    public function testItIgnoresAStaleRemoveIndex(): void
    {
        self::assertNull(ImportantAddressList::remove([], 2));
    }
}
