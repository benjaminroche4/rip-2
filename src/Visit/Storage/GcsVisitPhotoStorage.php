<?php

declare(strict_types=1);

namespace App\Visit\Storage;

use Google\Cloud\Storage\Bucket;
use GuzzleHttp\Psr7\StreamWrapper;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Google Cloud Storage implementation (prod): photos in the shared PRIVATE
 * bucket under visits/<reference>/photos/<uuid>.<ext>, per the bucket
 * convention. The bucket stays private — photos are streamed through the
 * visit controller, their object URLs are never exposed.
 */
final readonly class GcsVisitPhotoStorage implements VisitPhotoStorage
{
    public function __construct(
        private Bucket $bucket,
    ) {
    }

    public function store(string $visitRef, UploadedFile $file): string
    {
        $objectPath = VisitPhotoPath::build($visitRef, $file);

        $stream = fopen($file->getPathname(), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Uploaded photo unreadable.');
        }

        try {
            $this->bucket->upload($stream, [
                'name' => $objectPath,
                'metadata' => ['contentType' => (string) $file->getMimeType()],
            ]);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $objectPath;
    }

    public function exists(string $path): bool
    {
        return $this->bucket->object(VisitPhotoPath::guard($path))->exists();
    }

    public function readStream(string $path)
    {
        return StreamWrapper::getResource(
            $this->bucket->object(VisitPhotoPath::guard($path))->downloadAsStream(),
        );
    }

    public function delete(string $path): void
    {
        $object = $this->bucket->object(VisitPhotoPath::guard($path));
        if ($object->exists()) {
            $object->delete();
        }
    }
}
