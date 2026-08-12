<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage for deposited dossier documents. Never publicly reachable: every
 * read goes through an authenticated controller streaming the content.
 *
 * Implementations: LocalDocumentStorage (dev/test, files under storage/),
 * GcsDocumentStorage (private Google Cloud Storage bucket) and
 * DriveDocumentStorage (a per-person folder in the agency Shared Drive) —
 * selected by the DOSSIER_STORAGE env var via DocumentStorageFactory.
 */
interface DocumentStorage
{
    /**
     * Stores the uploaded file and returns an opaque handle (a UUID-based
     * name for disk/GCS, a Drive file id for Drive) persisted on the
     * DossierDocumentFile. The original client name is never used as a key.
     * The owning $document carries the person and piece type, which the
     * Drive backend uses to file it in the right per-person folder; the
     * disk/GCS backends ignore it.
     */
    public function store(Dossier $dossier, DossierDocument $document, UploadedFile $file): string;

    public function exists(Dossier $dossier, DossierDocumentFile $file): bool;

    /**
     * Content stream of the stored file, for controller streaming or zip
     * building. The caller closes the resource.
     *
     * @return resource
     */
    public function readStream(Dossier $dossier, DossierDocumentFile $file);

    /** Idempotent: deleting a missing file is a no-op. */
    public function delete(Dossier $dossier, DossierDocumentFile $file): void;
}
