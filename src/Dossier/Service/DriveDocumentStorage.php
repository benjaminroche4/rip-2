<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Shared\Google\DriveGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Google Drive implementation (prod, DOSSIER_STORAGE=drive): each piece is
 * uploaded into the depositor's per-person sub-folder inside the dossier's
 * Shared Drive folder, named after the piece type in French (mirroring the
 * ZIP export the staff already knows). The stored handle is the Drive file
 * id (persisted on DossierDocumentFile::storedName).
 *
 * The Shared Drive stays private: files are only ever streamed back through
 * the authenticated dossier controllers, never via a public Drive link.
 */
final readonly class DriveDocumentStorage implements DocumentStorage
{
    public function __construct(
        private DriveGateway $drive,
        private DossierDriveProvisioner $provisioner,
        private TranslatorInterface $translator,
    ) {
    }

    public function store(Dossier $dossier, DossierDocument $document, UploadedFile $file): string
    {
        $person = $document->getPerson();
        if (null === $person) {
            throw new \RuntimeException('Cannot store a document without an owning person.');
        }

        $folderId = $this->provisioner->ensurePersonFolder($dossier, $person);
        if (null === $folderId) {
            throw new \RuntimeException('Google Drive folder unavailable for dossier '.$dossier->getReference().'.');
        }

        $extension = $file->guessExtension() ?? 'bin';
        $label = $this->safe($this->translator->trans($document->getType()?->labelKey() ?? 'document', locale: 'fr'));
        // The new file is the (count+1)-th: suffix only from the second on,
        // matching the ZIP export naming (Label.pdf, Label-2.pdf, ...).
        $existing = $document->getFiles()->count();
        $suffix = 0 === $existing ? '' : '-'.($existing + 1);
        $name = $label.$suffix.'.'.$extension;

        $bytes = (string) file_get_contents($file->getPathname());
        $fileId = $this->drive->uploadFile($name, $folderId, $bytes, $file->getMimeType() ?: 'application/octet-stream', array_filter([
            'dossierReference' => (string) $dossier->getReference(),
            'personEmail' => (string) $person->getEmail(),
            'pieceType' => $document->getType()->value,
        ]));
        if (null === $fileId) {
            throw new \RuntimeException('Google Drive upload failed for dossier '.$dossier->getReference().'.');
        }

        return $fileId;
    }

    public function exists(Dossier $dossier, DossierDocumentFile $file): bool
    {
        return $this->drive->fileExists((string) $file->getStoredName());
    }

    public function readStream(Dossier $dossier, DossierDocumentFile $file)
    {
        return $this->drive->downloadStream((string) $file->getStoredName());
    }

    public function delete(Dossier $dossier, DossierDocumentFile $file): void
    {
        $this->drive->deleteFile((string) $file->getStoredName());
    }

    /** Drive-safe file segment (no slashes or control chars). */
    private function safe(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', trim($value));
        $value = (string) preg_replace('/[[:cntrl:]]+/', '', $value);

        return '' !== $value ? $value : 'document';
    }
}
