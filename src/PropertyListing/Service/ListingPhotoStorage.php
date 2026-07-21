<?php

namespace App\PropertyListing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stores listing photos under var/uploads (outside the web root: the form is
 * public and unauthenticated, uploaded content must never land in public/).
 * One folder per submission, named after the property address + owner name.
 */
final class ListingPhotoStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/properties/submissions')]
        private readonly string $storageDir,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param list<UploadedFile> $photos
     *
     * @return list<string> absolute paths of the stored files
     */
    public function store(string $address, string $fullName, array $photos, \DateTimeImmutable $date): array
    {
        $directory = $this->storageDir.'/'.$this->folderName($address, $fullName, $date);
        $this->filesystem->mkdir($directory);

        $paths = [];
        foreach ($photos as $photo) {
            $filename = bin2hex(random_bytes(16)).'.'.($photo->guessExtension() ?? 'bin');
            $paths[] = $photo->move($directory, $filename)->getPathname();
        }

        return $paths;
    }

    /**
     * @return list<string> absolute paths of the photos stored for a submission
     */
    public function photoPaths(string $folderName): array
    {
        return glob($this->storageDir.'/'.$folderName.'/*') ?: [];
    }

    /**
     * "12 Rue de Rivoli, 75001 Paris" + "Marie Dupont" + 2026-07-20
     * => "12ruederivoli75001parismariedupont-20260720"
     *
     * The date suffix keeps submissions sent on different days apart even
     * when the same owner re-submits the same address.
     */
    public function folderName(string $address, string $fullName, \DateTimeImmutable $date): string
    {
        $slug = strtolower($this->slugger->slug($address.$fullName)->toString());
        $slug = preg_replace('/[^a-z0-9]/', '', $slug) ?? '';

        return ('' !== $slug ? $slug : 'unknown').'-'.$date->format('Ymd');
    }
}
