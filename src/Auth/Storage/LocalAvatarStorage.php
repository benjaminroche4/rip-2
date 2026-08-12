<?php

declare(strict_types=1);

namespace App\Auth\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Disk implementation (dev/test): avatars under var/uploads/<objectPath>,
 * mirroring the bucket key layout (users/<ownerRef>/avatar/<uuid>.webp).
 * Kept out of public/ per the upload policy.
 */
final readonly class LocalAvatarStorage implements AvatarStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $baseDir,
        private LoggerInterface $logger,
    ) {
    }

    public function store(string $ownerRef, string $webpBytes): string
    {
        $objectPath = 'users/'.$this->safeRef($ownerRef).'/avatar/'.Uuid::v7()->toRfc4122().'.webp';
        $fullPath = $this->baseDir.'/'.$objectPath;

        $dir = \dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create avatar storage dir: '.$dir);
        }
        if (false === file_put_contents($fullPath, $webpBytes)) {
            throw new \RuntimeException('Cannot write avatar file.');
        }

        return $objectPath;
    }

    public function exists(string $path): bool
    {
        try {
            return is_file($this->resolve($path));
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->resolve($path), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Avatar not found: '.$path);
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $full = $this->resolve($path);
        if (is_file($full) && !@unlink($full)) {
            $this->logger->warning('LocalAvatarStorage: unlink failed', ['path' => $path]);
        }
    }

    /** Path traversal guard: keep only the expected users/.../avatar/uuid.webp shape. */
    private function resolve(string $path): string
    {
        if (1 !== preg_match('#^users/[0-9A-Za-z]+/avatar/[0-9a-f-]{36}\.webp$#', $path)) {
            throw new \RuntimeException('Illegal avatar path: '.$path);
        }

        return $this->baseDir.'/'.$path;
    }

    private function safeRef(string $ownerRef): string
    {
        if (1 !== preg_match('/^[0-9A-Za-z]+$/', $ownerRef)) {
            throw new \RuntimeException('Illegal avatar owner ref.');
        }

        return $ownerRef;
    }
}
