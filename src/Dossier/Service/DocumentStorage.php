<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocumentFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage for deposited dossier documents. Never publicly reachable: every
 * read goes through an authenticated controller streaming the content.
 *
 * Implementations: LocalDocumentStorage (dev/test, files under storage/)
 * and GcsDocumentStorage (prod, private Google Cloud Storage bucket) —
 * selected by the DOSSIER_STORAGE env var via DocumentStorageFactory.
 */
interface DocumentStorage
{
    /**
     * Stores the uploaded file under the dossier's namespace and returns the
     * stored (UUID-based) file name. The original client name is never used
     * as a storage key.
     */
    public function store(Dossier $dossier, UploadedFile $file): string;

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
