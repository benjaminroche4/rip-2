<?php

namespace App\Tests\PropertyListing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ListingPhotoStorageTest extends TestCase
{
    private function storage(): \App\PropertyListing\Service\ListingPhotoStorage
    {
        return new \App\PropertyListing\Service\LocalListingPhotoStorage(sys_get_temp_dir().'/listing-photos-test', new AsciiSlugger());
    }

    private function date(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-20');
    }

    public function testItBuildsAFlatLowercaseDateSuffixedFolderNameFromAddressAndName(): void
    {
        self::assertSame(
            '12ruederivoli75001parismariedupont-20260720',
            $this->storage()->folderName('12 Rue de Rivoli, 75001 Paris', 'Marie Dupont', $this->date()),
        );
    }

    public function testItStripsAccentsAndSpecialCharacters(): void
    {
        self::assertSame(
            '8avenuedeloperaeleonoremuller-20260720',
            $this->storage()->folderName("8 Avenue de l'Opéra !", 'Éléonore Müller', $this->date()),
        );
    }

    public function testItFallsBackWhenNothingUsableRemains(): void
    {
        self::assertSame('unknown-20260720', $this->storage()->folderName('!!!', '???', $this->date()));
    }
}
