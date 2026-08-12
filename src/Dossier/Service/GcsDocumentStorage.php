<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use Google\Cloud\Storage\Bucket;
use GuzzleHttp\Psr7\StreamWrapper;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Google Cloud Storage implementation (prod): objects live in a PRIVATE
 * bucket under dossiers/<reference>/<uuid>.<ext>, authenticated with the
 * service-account key. Synchronous REST calls only — fine on o2switch.
 * The bucket must stay private (no public access, uniform bucket-level
 * access): the app streams contents through authenticated controllers,
 * object URLs are never exposed.
 */
final readonly class GcsDocumentStorage implements DocumentStorage
{
    public function __construct(
        private Bucket $bucket,
    ) {
    }

    public function store(Dossier $dossier, DossierDocument $document, UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?? 'bin';
        $storedName = Uuid::v4()->toRfc4122().'.'.$extension;

        $stream = fopen($file->getPathname(), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Uploaded file unreadable.');
        }

        try {
            $this->bucket->upload($stream, [
                'name' => $this->objectName($dossier, $storedName),
                'metadata' => ['contentType' => $file->getMimeType() ?: 'application/octet-stream'],
            ]);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $storedName;
    }

    public function exists(Dossier $dossier, DossierDocumentFile $file): bool
    {
        return $this->object($dossier, $file)->exists();
    }

    public function readStream(Dossier $dossier, DossierDocumentFile $file)
    {
        return StreamWrapper::getResource($this->object($dossier, $file)->downloadAsStream());
    }

    public function delete(Dossier $dossier, DossierDocumentFile $file): void
    {
        $object = $this->object($dossier, $file);
        if ($object->exists()) {
            $object->delete();
        }
    }

    private function object(Dossier $dossier, DossierDocumentFile $file): \Google\Cloud\Storage\StorageObject
    {
        return $this->bucket->object($this->objectName($dossier, (string) $file->getStoredName()));
    }

    private function objectName(Dossier $dossier, string $storedName): string
    {
        $ref = (string) $dossier->getReference();
        if ($this->unsafe($ref) || $this->unsafe($storedName)) {
            throw new \RuntimeException('Illegal dossier document path.');
        }

        return 'dossiers/'.$ref.'/documents/'.$storedName;
    }

    /** Rejects any path-traversal segment (slash, backslash, "..", null). */
    private function unsafe(string $segment): bool
    {
        return '' === $segment || str_contains($segment, '/') || str_contains($segment, '\\')
            || str_contains($segment, '..') || str_contains($segment, "\0");
    }
}
