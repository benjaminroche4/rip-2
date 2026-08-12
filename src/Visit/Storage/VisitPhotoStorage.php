<?php

declare(strict_types=1);

namespace App\Visit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores visit property photos under visits/<reference>/photos/<uuid>.<ext>,
 * following the bucket convention {domain}/{entity-ref}/{asset-type}. Disk
 * in dev, private GCS bucket in prod — always streamed through an
 * authenticated controller, never a public object URL.
 *
 * store() returns the full object path; it is what gets persisted on the
 * VisitPhoto row and passed back to exists()/readStream()/delete().
 */
interface VisitPhotoStorage
{
    /**
     * @param string $visitRef public visit reference ("VS-087526")
     *
     * @return string the full object path, e.g. visits/VS-087526/photos/<uuid>.webp
     */
    public function store(string $visitRef, UploadedFile $file): string;

    public function exists(string $path): bool;

    /**
     * @return resource a readable stream of the stored photo
     */
    public function readStream(string $path);

    /** Best-effort delete: after this call the object no longer exists. */
    public function delete(string $path): void;
}
