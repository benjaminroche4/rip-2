<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores public-form listing photos (never in public/). Local disk in dev,
 * private GCS bucket in prod under listings/<folder>/photos/<uuid>.<ext>.
 * Photos are write-once, read-once (attached to the internal email), then
 * expire (GCS lifecycle rule, or the local purge command).
 */
interface ListingPhotoStorage
{
    public function folderName(string $address, string $fullName, \DateTimeImmutable $date): string;

    /**
     * @param list<UploadedFile> $photos
     */
    public function store(string $folderName, array $photos): void;

    /**
     * Stored photos of a submission as lazy descriptors: size is known
     * upfront so the caller can honour a byte budget WITHOUT downloading
     * every object; bytes() pulls the content only when actually attached.
     *
     * @return iterable<ListingPhoto>
     */
    public function attachments(string $folderName): iterable;
}
