<?php

declare(strict_types=1);

namespace App\Shared\Google;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Google Drive API v3 client for dossier document storage, built on
 * the agency Workspace service account (same JWT-by-hand approach as
 * GoogleCalendarClient, no heavy SDK). The service account is a member of a
 * Shared Drive, so files it creates are owned by the organisation (pooled
 * storage) rather than by the service account (which has no usable quota).
 *
 * Because it targets a Shared Drive, every call carries supportsAllDrives=1.
 * Unconfigured (empty env) or failing mutations degrade to null / no-op so
 * the dossier flow never hard-fails on a Drive outage.
 */
final class GoogleDriveClient implements DriveGateway
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const SCOPE = 'https://www.googleapis.com/auth/drive';

    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(GOOGLE_DRIVE_KEY_FILE)%')]
        private readonly string $keyFile,
        #[Autowire('%env(GOOGLE_DRIVE_SHARED_DRIVE_ID)%')]
        private readonly string $sharedDriveId,
        // Optional: impersonate a Workspace user (domain-wide delegation) when
        // storing in a My Drive instead of a Shared Drive. Empty by default.
        #[Autowire('%env(GOOGLE_DRIVE_IMPERSONATE)%')]
        private readonly string $impersonate = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->keyFile && '' !== $this->sharedDriveId;
    }

    public function sharedDriveId(): string
    {
        return $this->sharedDriveId;
    }

    public function ensureFolder(string $name, string $parentId, array $appProperties = []): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $existing = $this->findFolder($name, $parentId);
        if (null !== $existing) {
            return $existing;
        }

        $created = $this->request('POST', self::FILES_URL, [
            'query' => ['supportsAllDrives' => 'true', 'fields' => 'id'],
            'json' => array_filter([
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
                'appProperties' => $appProperties ?: null,
            ], static fn ($v): bool => null !== $v),
        ]);

        $id = $created['id'] ?? null;

        return \is_string($id) ? $id : null;
    }

    public function uploadFile(string $name, string $parentId, string $bytes, string $mimeType, array $appProperties = []): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $token = $this->accessToken();
        if (null === $token) {
            return null;
        }

        $metadata = array_filter([
            'name' => $name,
            'parents' => [$parentId],
            'appProperties' => $appProperties ?: null,
        ], static fn ($v): bool => null !== $v);

        $boundary = 'rip'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .(string) json_encode($metadata)."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mimeType}\r\n\r\n"
            .$bytes."\r\n"
            ."--{$boundary}--";

        try {
            $response = $this->httpClient->request('POST', self::UPLOAD_URL, [
                'query' => ['uploadType' => 'multipart', 'supportsAllDrives' => 'true', 'fields' => 'id'],
                'auth_bearer' => $token,
                'headers' => ['Content-Type' => "multipart/related; boundary={$boundary}"],
                'body' => $body,
                'timeout' => 10,
                'max_duration' => 30,
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->error('Google Drive upload failed with status '.$response->getStatusCode());

                return null;
            }
            $id = $response->toArray()['id'] ?? null;

            return \is_string($id) ? $id : null;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Google Drive upload errored: '.$e->getMessage());

            return null;
        }
    }

    public function downloadStream(string $fileId)
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Google Drive is not configured.');
        }
        $token = $this->accessToken();
        if (null === $token) {
            throw new \RuntimeException('Google Drive token unavailable.');
        }

        $response = $this->httpClient->request('GET', self::FILES_URL.'/'.rawurlencode($fileId), [
            'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'],
            'auth_bearer' => $token,
            'timeout' => 10,
            'max_duration' => 30,
        ]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Google Drive file unreadable: '.$fileId);
        }
        if (!$response instanceof StreamableInterface) {
            throw new \LogicException(\sprintf('HTTP client response "%s" cannot be streamed: it does not implement "%s".', $response::class, StreamableInterface::class));
        }

        return $response->toStream();
    }

    public function fileExists(string $fileId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $token = $this->accessToken();
        if (null === $token) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', self::FILES_URL.'/'.rawurlencode($fileId), [
                'query' => ['supportsAllDrives' => 'true', 'fields' => 'id,trashed'],
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if (200 !== $response->getStatusCode()) {
                return false;
            }

            return true !== ($response->toArray()['trashed'] ?? false);
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public function deleteFile(string $fileId): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $token = $this->accessToken();
        if (null === $token) {
            return;
        }

        try {
            $this->httpClient->request('DELETE', self::FILES_URL.'/'.rawurlencode($fileId), [
                'query' => ['supportsAllDrives' => 'true'],
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Google Drive delete errored: '.$e->getMessage());
        }
    }

    public function shareRead(string $fileId, string $email): ?string
    {
        if (!$this->isConfigured() || '' === $email) {
            return null;
        }

        $created = $this->request('POST', self::FILES_URL.'/'.rawurlencode($fileId).'/permissions', [
            'query' => ['supportsAllDrives' => 'true', 'sendNotificationEmail' => 'false', 'fields' => 'id'],
            'json' => ['role' => 'reader', 'type' => 'user', 'emailAddress' => $email],
        ]);
        $id = $created['id'] ?? null;

        return \is_string($id) ? $id : null;
    }

    public function removePermission(string $fileId, string $permissionId): void
    {
        if (!$this->isConfigured() || '' === $permissionId) {
            return;
        }
        $token = $this->accessToken();
        if (null === $token) {
            return;
        }

        try {
            $this->httpClient->request('DELETE', self::FILES_URL.'/'.rawurlencode($fileId).'/permissions/'.rawurlencode($permissionId), [
                'query' => ['supportsAllDrives' => 'true'],
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 10,
            ])->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Google Drive permission removal errored: '.$e->getMessage());
        }
    }

    private function findFolder(string $name, string $parentId): ?string
    {
        $escaped = str_replace("'", "\\'", $name);
        $result = $this->request('GET', self::FILES_URL, [
            'query' => [
                'q' => \sprintf("mimeType='%s' and '%s' in parents and name='%s' and trashed=false", self::FOLDER_MIME, $parentId, $escaped),
                'corpora' => 'drive',
                'driveId' => $this->sharedDriveId,
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives' => 'true',
                'fields' => 'files(id)',
                'pageSize' => '1',
            ],
        ]);
        $id = $result['files'][0]['id'] ?? null;

        return \is_string($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, array $options): ?array
    {
        $token = $this->accessToken();
        if (null === $token) {
            return null;
        }

        try {
            $response = $this->httpClient->request($method, $url, [
                ...$options,
                'auth_bearer' => $token,
                'timeout' => 5,
                'max_duration' => 15,
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->error(\sprintf('Google Drive API %s %s returned %d', $method, $url, $response->getStatusCode()));

                return null;
            }

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $this->logger->error(\sprintf('Google Drive API %s %s errored: %s', $method, $url, $e->getMessage()));

            return null;
        }
    }

    /**
     * Service-account JWT grant. No impersonation by default (the service
     * account acts as a Shared Drive member); GOOGLE_DRIVE_IMPERSONATE sets
     * the `sub` claim for the domain-wide-delegation / My Drive fallback.
     */
    private function accessToken(): ?string
    {
        if (null !== $this->accessToken) {
            return $this->accessToken;
        }

        // The env var holds either a path to the key file, or the key JSON
        // itself base64-encoded (handy on hosts where uploading a file
        // outside the webroot is a hassle).
        if (json_validate((string) base64_decode($this->keyFile, true))) {
            $raw = (string) base64_decode($this->keyFile, true);
        } else {
            $raw = @file_get_contents($this->keyFile);
            if (false === $raw) {
                $this->logger->error('Google Drive key is unreadable (path missing or corrupted base64).');

                return null;
            }
        }
        /** @var array{client_email?: string, private_key?: string} $key */
        $key = json_decode($raw, true) ?: [];
        if (!isset($key['client_email'], $key['private_key'])) {
            $this->logger->error('Google Drive key file is missing client_email or private_key.');

            return null;
        }

        $now = time();
        $claims = array_filter([
            'iss' => $key['client_email'],
            'sub' => '' !== $this->impersonate ? $this->impersonate : null,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], static fn ($v): bool => null !== $v);
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode($claims)),
        ];
        $signature = '';
        if (!openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            $this->logger->error('Google Drive JWT signing failed.');

            return null;
        }
        $segments[] = $this->base64Url($signature);

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'timeout' => 5,
                'max_duration' => 10,
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => implode('.', $segments),
                ],
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->error('Google Drive token exchange failed with status '.$response->getStatusCode());

                return null;
            }
            $token = $response->toArray()['access_token'] ?? null;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Google Drive token exchange errored: '.$e->getMessage());

            return null;
        }

        return $this->accessToken = \is_string($token) ? $token : null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
