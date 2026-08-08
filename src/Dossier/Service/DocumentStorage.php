<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocumentFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Disk storage for deposited dossier documents. Files live outside public/
 * (storage/dossiers/<reference>/<uuid>.<ext>) so every read goes through an
 * authenticated controller; the deploy process and cache:clear never touch
 * this directory.
 */
final readonly class DocumentStorage
{
    public function __construct(
        #[Autowire('%dossier_storage_dir%')]
        private string $baseDir,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Moves the uploaded file under the dossier's directory and returns the
     * stored (UUID-based) file name. The original client name is never used
     * on disk.
     */
    public function store(Dossier $dossier, UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?? 'bin';
        $storedName = Uuid::v4()->toRfc4122().'.'.$extension;

        $file->move($this->dossierDir($dossier), $storedName);

        return $storedName;
    }

    public function path(Dossier $dossier, DossierDocumentFile $file): string
    {
        return $this->dossierDir($dossier).'/'.$file->getStoredName();
    }

    public function delete(Dossier $dossier, DossierDocumentFile $file): void
    {
        $this->filesystem->remove($this->path($dossier, $file));
    }

    private function dossierDir(Dossier $dossier): string
    {
        return $this->baseDir.'/'.$dossier->getReference();
    }
}
