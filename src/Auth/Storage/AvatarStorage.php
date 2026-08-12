<?php

declare(strict_types=1);

namespace App\Auth\Storage;

/**
 * Stores user avatars (already normalized to 256×256 WebP) under the
 * per-user object key users/<ownerRef>/avatar/<uuid>.webp, following the
 * bucket convention {domain}/{entity-ref}/{asset-type}/{uuid}.{ext}. The
 * per-user prefix lets us wipe everything belonging to an account in one
 * prefix delete (GDPR). Disk in dev, private GCS bucket in prod (streamed
 * through the app, never a public object URL).
 *
 * store() returns the full relative path; it is what gets persisted on the
 * user and passed back to exists()/readStream()/delete().
 */
interface AvatarStorage
{
    /**
     * @param string $ownerRef stable public id of the owner (User ULID)
     *
     * @return string the full object path, e.g. users/<ulid>/avatar/<uuid>.webp
     */
    public function store(string $ownerRef, string $webpBytes): string;

    public function exists(string $path): bool;

    /**
     * @return resource a readable stream of the stored WebP
     */
    public function readStream(string $path);

    /** Best-effort delete: after this call the object no longer exists. */
    public function delete(string $path): void;
}
