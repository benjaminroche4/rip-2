<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Disk implementation (dev/test): one folder per submission under
 * var/uploads/properties/submissions/<folder>/.
 */
final class LocalListingPhotoStorage implements ListingPhotoStorage
{
    use ListingFolderNaming;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/properties/submissions')]
        private readonly string $storageDir,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function store(string $folderName, array $photos): void
    {
        $directory = $this->storageDir.'/'.$folderName;
        $this->filesystem->mkdir($directory);

        foreach ($photos as $photo) {
            $filename = bin2hex(random_bytes(16)).'.'.($photo->guessExtension() ?? 'bin');
            $photo->move($directory, $filename);
        }
    }

    public function attachments(string $folderName): iterable
    {
        foreach (glob($this->storageDir.'/'.$folderName.'/*') ?: [] as $path) {
            yield new ListingPhoto(
                basename($path),
                mime_content_type($path) ?: 'application/octet-stream',
                (int) (@filesize($path) ?: 0),
                static fn (): string => (string) @file_get_contents($path),
            );
        }
    }

    protected function slugger(): SluggerInterface
    {
        return $this->slugger;
    }
}
