<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a remote avatar (e.g. Google account picture), normalizes it to a
 * 256×256 WebP and stores it under var/uploads/avatars/<uuid>.webp.
 *
 * Avatars never live in /public/ (CLAUDE.md upload policy) — they are served
 * by App\Auth\Controller\AvatarController which sets long cache headers and
 * never hits the kernel for static reads once the browser has cached.
 */
final readonly class AvatarDownloader
{
    private const MAX_BYTES = 5 * 1024 * 1024;       // 5 MB hard cap on the source
    private const TIMEOUT_SECONDS = 5;                // download timeout
    private const TARGET_SIZE = 256;                  // square edge in pixels
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/uploads/avatars')]
        private string $storageDir,
    ) {
    }

    /**
     * Downloads the URL, normalizes to WebP 256×256 and stores it. Returns
     * the new filename or null if the operation failed for any reason
     * (network, decode, size). Failures are logged, never thrown — a missing
     * avatar is not worth blocking a login.
     */
    public function downloadAndStore(string $url): ?string
    {
        $bytes = $this->fetch($url);
        if (null === $bytes) {
            return null;
        }

        $filename = Uuid::v7()->toRfc4122().'.webp';
        $path = $this->storageDir.'/'.$filename;

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0o755, true) && !is_dir($this->storageDir)) {
            $this->logger->error('AvatarDownloader: cannot create storage dir', ['dir' => $this->storageDir]);

            return null;
        }

        if (!$this->normalizeAndWrite($bytes, $path)) {
            return null;
        }

        return $filename;
    }

    /**
     * Best-effort delete. Missing files are not an error — the only invariant
     * is "after this call, the file no longer exists on disk".
     */
    public function delete(string $filename): void
    {
        $path = $this->storageDir.'/'.$filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'headers' => [
                    'Accept' => 'image/*',
                    // Google CDN refuses requests with a referer from another origin.
                    'Referer' => '',
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('AvatarDownloader: non-200 response', [
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            $mime = strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            $mime = explode(';', $mime, 2)[0];
            if (!\in_array($mime, self::ALLOWED_MIMES, true)) {
                $this->logger->warning('AvatarDownloader: unsupported mime', ['url' => $url, 'mime' => $mime]);

                return null;
            }

            $bytes = $response->getContent(false);
        } catch (ExceptionInterface|TransportException $e) {
            $this->logger->warning('AvatarDownloader: fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (\strlen($bytes) > self::MAX_BYTES) {
            $this->logger->warning('AvatarDownloader: payload too large', [
                'url' => $url,
                'bytes' => \strlen($bytes),
            ]);

            return null;
        }

        return $bytes;
    }

    private function normalizeAndWrite(string $bytes, string $path): bool
    {
        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            $this->logger->warning('AvatarDownloader: imagecreatefromstring failed', ['path' => $path]);

            return false;
        }

        try {
            $resized = imagescale($source, self::TARGET_SIZE, self::TARGET_SIZE);
            if (false === $resized) {
                $this->logger->warning('AvatarDownloader: imagescale failed', ['path' => $path]);

                return false;
            }

            try {
                if (!imagewebp($resized, $path, 80)) {
                    $this->logger->warning('AvatarDownloader: imagewebp failed', ['path' => $path]);

                    return false;
                }
            } finally {
                imagedestroy($resized);
            }
        } finally {
            imagedestroy($source);
        }

        return true;
    }
}
