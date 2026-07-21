<?php

namespace App\PropertyListing\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Retention policy for listing photo submissions: var/uploads grows with
 * every submission, so a cron on o2switch runs this to drop folders once
 * the team no longer needs them.
 */
#[AsCommand(
    name: 'app:listing:purge-photos',
    description: 'Delete listing photo submission folders older than the retention period',
)]
final class PurgeListingPhotosCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/properties/submissions')]
        private readonly string $storageDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Delete folders whose last change is older than this many days')]
        int $days = 90,
    ): int {
        $threshold = time() - $days * 86400;
        $removed = 0;

        foreach (glob($this->storageDir.'/*', \GLOB_ONLYDIR) ?: [] as $directory) {
            if (filemtime($directory) < $threshold) {
                $this->filesystem->remove($directory);
                ++$removed;
            }
        }

        $io->success(\sprintf('%d submission folder(s) older than %d days removed.', $removed, $days));

        return Command::SUCCESS;
    }
}
