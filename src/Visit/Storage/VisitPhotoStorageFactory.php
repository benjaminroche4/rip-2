<?php

declare(strict_types=1);

namespace App\Visit\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the VisitPhotoStorage from VISIT_PHOTO_STORAGE ('local' or 'gcs').
 * GCS reuses the same bucket and service-account key as the dossier
 * documents and avatars; the GCS client is only built when actually
 * selected, so dev and test never need Google credentials.
 */
final readonly class VisitPhotoStorageFactory
{
    public function __construct(
        private LocalVisitPhotoStorage $local,
        private \App\Shared\Gcs\GcsBucketFactory $buckets,
        #[Autowire(env: 'VISIT_PHOTO_STORAGE')]
        private string $driver = 'local',
    ) {
    }

    public function create(): VisitPhotoStorage
    {
        return match ($this->driver) {
            'local' => $this->local,
            'gcs' => $this->createGcs(),
            default => throw new \InvalidArgumentException(sprintf('Unknown VISIT_PHOTO_STORAGE driver "%s" (use "local" or "gcs").', $this->driver)),
        };
    }

    private function createGcs(): GcsVisitPhotoStorage
    {
        return new GcsVisitPhotoStorage($this->buckets->bucket());
    }
}
