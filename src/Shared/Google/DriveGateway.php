<?php

declare(strict_types=1);

namespace App\Shared\Google;

/**
 * Narrow Google Drive port used by the dossier storage. Kept as an interface
 * so the provisioner and storage can be unit-tested against a fake, without
 * hitting the real API or the concrete (final) HTTP client.
 *
 * Every method is a best-effort no-op when Drive is unconfigured: creators
 * return null, mutators do nothing. Callers must treat a null id as
 * "Drive unavailable" and degrade gracefully.
 */
interface DriveGateway
{
    public function isConfigured(): bool;

    /** Id of the agency Shared Drive (the parent of every dossier folder). */
    public function sharedDriveId(): string;

    /**
     * Finds (by appProperties marker under the parent) or creates a folder,
     * and returns its Drive id. Idempotent: a matching folder is reused
     * rather than duplicated. Returns null when Drive is unavailable.
     *
     * @param array<string, string> $appProperties private app metadata (e.g. dossierReference)
     */
    public function ensureFolder(string $name, string $parentId, array $appProperties = []): ?string;

    /**
     * Uploads bytes as a new file under $parentId and returns its Drive id,
     * or null when Drive is unavailable.
     *
     * @param array<string, string> $appProperties
     */
    public function uploadFile(string $name, string $parentId, string $bytes, string $mimeType, array $appProperties = []): ?string;

    /**
     * Content stream of a Drive file. Throws when Drive is unavailable or the
     * file cannot be read (mirrors the disk/GCS backends).
     *
     * @return resource
     */
    public function downloadStream(string $fileId);

    public function fileExists(string $fileId): bool;

    /** Idempotent: deleting a missing/unknown file is a no-op. */
    public function deleteFile(string $fileId): void;

    /**
     * Grants read access on a file/folder to an email and returns the created
     * permission id (for later revocation), or null when Drive is unavailable
     * or the address is empty.
     */
    public function shareRead(string $fileId, string $email): ?string;

    /** Idempotent: removing a missing permission is a no-op. */
    public function removePermission(string $fileId, string $permissionId): void;
}
