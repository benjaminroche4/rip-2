<?php

declare(strict_types=1);

namespace App\Auth\Storage;

use Google\Cloud\Storage\Bucket;
use GuzzleHttp\Psr7\StreamWrapper;
use Symfony\Component\Uid\Uuid;

/**
 * Google Cloud Storage implementation (prod): avatars in the shared PRIVATE
 * bucket under users/<ownerRef>/avatar/<uuid>.webp, per the bucket
 * convention. The bucket stays private — avatars are streamed through
 * AvatarController, their object URLs are never exposed.
 */
final readonly class GcsAvatarStorage implements AvatarStorage
{
    public function __construct(
        private Bucket $bucket,
    ) {
    }

    public function store(string $ownerRef, string $webpBytes): string
    {
        if (1 !== preg_match('/^[0-9A-Za-z]+$/', $ownerRef)) {
            throw new \RuntimeException('Illegal avatar owner ref.');
        }

        $objectPath = 'users/'.$ownerRef.'/avatar/'.Uuid::v7()->toRfc4122().'.webp';
        $this->bucket->upload($webpBytes, [
            'name' => $objectPath,
            'metadata' => ['contentType' => 'image/webp'],
        ]);

        return $objectPath;
    }

    public function exists(string $path): bool
    {
        try {
            return $this->bucket->object($this->guard($path))->exists();
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function readStream(string $path)
    {
        return StreamWrapper::getResource(
            $this->bucket->object($this->guard($path))->downloadAsStream(),
        );
    }

    public function delete(string $path): void
    {
        $object = $this->bucket->object($this->guard($path));
        if ($object->exists()) {
            $object->delete();
        }
    }

    private function guard(string $path): string
    {
        if (1 !== preg_match('#^users/[0-9A-Za-z]+/avatar/[0-9a-f-]{36}\.webp$#', $path)) {
            throw new \RuntimeException('Illegal avatar path: '.$path);
        }

        return $path;
    }
}
