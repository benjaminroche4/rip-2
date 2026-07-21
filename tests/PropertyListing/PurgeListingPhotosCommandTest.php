<?php

namespace App\Tests\PropertyListing;

use App\PropertyListing\Command\PurgeListingPhotosCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class PurgeListingPhotosCommandTest extends TestCase
{
    public function testItRemovesOnlyFoldersOlderThanTheRetentionPeriod(): void
    {
        $storageDir = sys_get_temp_dir().'/listing-purge-test-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$storageDir.'/old-20260101', $storageDir.'/fresh-20260720']);
        touch($storageDir.'/old-20260101', time() - 120 * 86400);

        $command = new PurgeListingPhotosCommand($storageDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());

        try {
            self::assertSame(Command::SUCCESS, $command($io, 90));
            self::assertDirectoryDoesNotExist($storageDir.'/old-20260101');
            self::assertDirectoryExists($storageDir.'/fresh-20260720');
        } finally {
            $filesystem->remove($storageDir);
        }
    }
}
