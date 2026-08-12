<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

use Google\Cloud\Storage\Bucket;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Google Cloud Storage implementation (prod): photos in the shared PRIVATE
 * bucket under listings/<folder>/photos/<uuid>.<ext>. A bucket lifecycle
 * rule expires the listings/ prefix (no cron needed).
 */
final class GcsListingPhotoStorage implements ListingPhotoStorage
{
    use ListingFolderNaming;

    public function __construct(
        private readonly Bucket $bucket,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function store(string $folderName, array $photos): void
    {
        foreach ($photos as $photo) {
            $ext = $photo->guessExtension() ?? 'bin';
            $stream = fopen($photo->getPathname(), 'r');
            if (false === $stream) {
                continue;
            }
            try {
                $this->bucket->upload($stream, [
                    'name' => $this->prefix($folderName).Uuid::v7()->toRfc4122().'.'.$ext,
                    'metadata' => ['contentType' => $photo->getMimeType() ?: 'application/octet-stream'],
                ]);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function attachments(string $folderName): iterable
    {
        foreach ($this->bucket->objects(['prefix' => $this->prefix($folderName)]) as $object) {
            $info = $object->info();
            yield new ListingPhoto(
                basename($object->name()),
                (string) ($info['contentType'] ?? 'application/octet-stream'),
                (int) ($info['size'] ?? 0),
                static function () use ($object): string {
                    try {
                        return (string) $object->downloadAsString();
                    } catch (\Throwable) {
                        // One unreadable object must not abort the whole email.
                        return '';
                    }
                },
            );
        }
    }

    private function prefix(string $folderName): string
    {
        // Folder names are slug-<date>: strip anything unexpected defensively.
        $safe = preg_replace('/[^a-z0-9-]/', '', strtolower($folderName)) ?? '';

        return 'listings/'.$safe.'/photos/';
    }

    protected function slugger(): SluggerInterface
    {
        return $this->slugger;
    }
}
