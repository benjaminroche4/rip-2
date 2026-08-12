<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Disk implementation (dev/test): files live outside public/
 * (storage/dossiers/<reference>/documents/<uuid>.<ext>), the deploy process and
 * cache:clear never touch this directory.
 */
final readonly class LocalDocumentStorage implements DocumentStorage
{
    public function __construct(
        #[Autowire('%dossier_storage_dir%')]
        private string $baseDir,
        private Filesystem $filesystem,
    ) {
    }

    public function store(Dossier $dossier, DossierDocument $document, UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?? 'bin';
        $storedName = Uuid::v4()->toRfc4122().'.'.$extension;

        $file->move($this->dossierDir($dossier), $storedName);

        return $storedName;
    }

    public function exists(Dossier $dossier, DossierDocumentFile $file): bool
    {
        return is_file($this->path($dossier, $file));
    }

    public function readStream(Dossier $dossier, DossierDocumentFile $file)
    {
        $stream = @fopen($this->path($dossier, $file), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Stored file unreadable: '.$file->getStoredName());
        }

        return $stream;
    }

    public function delete(Dossier $dossier, DossierDocumentFile $file): void
    {
        $this->filesystem->remove($this->path($dossier, $file));
    }

    private function path(Dossier $dossier, DossierDocumentFile $file): string
    {
        $stored = (string) $file->getStoredName();
        if ($this->unsafe($stored)) {
            throw new \RuntimeException('Illegal dossier document name.');
        }

        return $this->dossierDir($dossier).'/'.$stored;
    }

    private function dossierDir(Dossier $dossier): string
    {
        $ref = (string) $dossier->getReference();
        if ($this->unsafe($ref)) {
            throw new \RuntimeException('Illegal dossier reference.');
        }

        return $this->baseDir.'/'.$ref.'/documents';
    }

    /** Rejects any path-traversal segment (slash, backslash, "..", null). */
    private function unsafe(string $segment): bool
    {
        return '' === $segment || str_contains($segment, '/') || str_contains($segment, '\\')
            || str_contains($segment, '..') || str_contains($segment, "\0");
    }
}
