<?php

declare(strict_types=1);

namespace App\Tests\Shared\Google;

use App\Shared\Google\DriveGateway;

/**
 * In-memory DriveGateway for tests: records calls and hands back predictable
 * ids, with no HTTP. `configured` toggles the unconfigured no-op behaviour.
 */
final class FakeDriveGateway implements DriveGateway
{
    /** @var list<array{name: string, parent: string, props: array<string, string>}> */
    public array $createdFolders = [];

    /** @var list<array{name: string, parent: string, mime: string, size: int, props: array<string, string>}> */
    public array $uploads = [];

    /** @var array<string, string> fileId => raw bytes */
    public array $contents = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{file: string, email: string}> */
    public array $shares = [];

    /** @var list<array{file: string, permission: string}> */
    public array $revocations = [];

    private int $seq = 0;

    public function __construct(
        private readonly bool $configured = true,
        private readonly string $sharedDriveId = 'shared-drive-root',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sharedDriveId(): string
    {
        return $this->sharedDriveId;
    }

    public function ensureFolder(string $name, string $parentId, array $appProperties = []): ?string
    {
        if (!$this->configured) {
            return null;
        }
        $this->createdFolders[] = ['name' => $name, 'parent' => $parentId, 'props' => $appProperties];

        return 'folder-'.(++$this->seq);
    }

    public function uploadFile(string $name, string $parentId, string $bytes, string $mimeType, array $appProperties = []): ?string
    {
        if (!$this->configured) {
            return null;
        }
        $this->uploads[] = ['name' => $name, 'parent' => $parentId, 'mime' => $mimeType, 'size' => \strlen($bytes), 'props' => $appProperties];
        $id = 'file-'.(++$this->seq);
        $this->contents[$id] = $bytes;

        return $id;
    }

    public function downloadStream(string $fileId)
    {
        if (!isset($this->contents[$fileId])) {
            throw new \RuntimeException('Unknown Drive file: '.$fileId);
        }
        $stream = fopen('php://temp', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $this->contents[$fileId]);
        rewind($stream);

        return $stream;
    }

    public function fileExists(string $fileId): bool
    {
        return isset($this->contents[$fileId]);
    }

    public function deleteFile(string $fileId): void
    {
        $this->deleted[] = $fileId;
        unset($this->contents[$fileId]);
    }

    public function shareRead(string $fileId, string $email): ?string
    {
        if (!$this->configured || '' === $email) {
            return null;
        }
        $this->shares[] = ['file' => $fileId, 'email' => $email];

        return 'perm-'.(++$this->seq);
    }

    public function removePermission(string $fileId, string $permissionId): void
    {
        $this->revocations[] = ['file' => $fileId, 'permission' => $permissionId];
    }
}
