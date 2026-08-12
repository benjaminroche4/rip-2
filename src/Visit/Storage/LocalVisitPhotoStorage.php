<?php

declare(strict_types=1);

namespace App\Visit\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Disk implementation (dev/test): photos under var/uploads/<objectPath>,
 * mirroring the bucket key layout (visits/<ref>/photos/<uuid>.<ext>).
 * Kept out of public/ per the upload policy.
 */
final readonly class LocalVisitPhotoStorage implements VisitPhotoStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $baseDir,
        private LoggerInterface $logger,
    ) {
    }

    public function store(string $visitRef, UploadedFile $file): string
    {
        $objectPath = VisitPhotoPath::build($visitRef, $file);
        $fullPath = $this->baseDir.'/'.$objectPath;

        $dir = \dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create visit photo storage dir: '.$dir);
        }
        $file->move($dir, basename($objectPath));

        return $objectPath;
    }

    public function exists(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->resolve($path), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Visit photo not found: '.$path);
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $full = $this->resolve($path);
        if (is_file($full) && !@unlink($full)) {
            $this->logger->warning('LocalVisitPhotoStorage: unlink failed', ['path' => $path]);
        }
    }

    /** Path traversal guard: keep only the expected object path shape. */
    private function resolve(string $path): string
    {
        return $this->baseDir.'/'.VisitPhotoPath::guard($path);
    }
}
