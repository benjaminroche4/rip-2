<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a remote avatar (e.g. Google account picture), normalizes it to a
 * 256×256 WebP and hands it to the AvatarStorage (disk in dev, a
 * private GCS bucket in prod).
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
        private \App\Auth\Storage\AvatarStorage $storage,
    ) {
    }

    /**
     * Downloads the URL, normalizes to WebP 256×256 and stores it under the
     * owner's prefix. Returns the object path or null if the operation failed
     * (network, decode, size). Failures are logged, never thrown — a missing
     * avatar is not worth blocking a login.
     */
    public function downloadAndStore(string $url, string $ownerRef): ?string
    {
        $bytes = $this->fetch($url);
        if (null === $bytes) {
            return null;
        }

        return $this->storeFromBytes($bytes, $ownerRef);
    }

    /**
     * Normalizes raw image bytes (e.g. an admin upload) to WebP 256×256 and
     * stores them under the owner's prefix. Returns the object path or null on failure (decode,
     * size, disk). Failures are logged, never thrown.
     */
    public function storeFromBytes(string $bytes, string $ownerRef): ?string
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            $this->logger->warning('AvatarDownloader: payload too large', ['bytes' => \strlen($bytes)]);

            return null;
        }

        $webp = $this->normalizeToWebp($bytes);
        if (null === $webp) {
            return null;
        }

        try {
            return $this->storage->store($ownerRef, $webp);
        } catch (\Throwable $e) {
            $this->logger->error('AvatarDownloader: storage failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Best-effort delete. Missing files are not an error — the only invariant
     * is "after this call, the file no longer exists on disk".
     */
    public function delete(string $filename): void
    {
        try {
            $this->storage->delete($filename);
        } catch (\Throwable $e) {
            $this->logger->warning('AvatarDownloader: delete failed', ['file' => $filename, 'error' => $e->getMessage()]);
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

    /** Decode → 256×256 square → WebP bytes; null on any failure. */
    private function normalizeToWebp(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            $this->logger->warning('AvatarDownloader: imagecreatefromstring failed');

            return null;
        }

        try {
            $resized = imagescale($source, self::TARGET_SIZE, self::TARGET_SIZE);
            if (false === $resized) {
                $this->logger->warning('AvatarDownloader: imagescale failed');

                return null;
            }

            try {
                ob_start();
                $ok = imagewebp($resized, null, 80);
                $webp = (string) ob_get_clean();
                if (!$ok || '' === $webp) {
                    $this->logger->warning('AvatarDownloader: imagewebp failed');

                    return null;
                }

                return $webp;
            } finally {
                imagedestroy($resized);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
