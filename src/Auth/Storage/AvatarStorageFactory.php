<?php

declare(strict_types=1);

namespace App\Auth\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the AvatarStorage from AVATAR_STORAGE ('local' or 'gcs'). GCS reuses
 * the same bucket and service-account key as the dossier documents (avatars
 * just live under the avatars/ prefix), so no extra credentials to manage.
 * The GCS client is only built when actually selected: dev and test never
 * need Google credentials.
 */
final readonly class AvatarStorageFactory
{
    public function __construct(
        private LocalAvatarStorage $local,
        private \App\Shared\Gcs\GcsBucketFactory $buckets,
        #[Autowire(env: 'AVATAR_STORAGE')]
        private string $driver = 'local',
    ) {
    }

    public function create(): AvatarStorage
    {
        return match ($this->driver) {
            'local' => $this->local,
            'gcs' => $this->createGcs(),
            default => throw new \InvalidArgumentException(sprintf('Unknown AVATAR_STORAGE driver "%s" (use "local" or "gcs").', $this->driver)),
        };
    }

    private function createGcs(): GcsAvatarStorage
    {
        return new GcsAvatarStorage($this->buckets->bucket());
    }
}
