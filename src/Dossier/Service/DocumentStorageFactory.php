<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the DocumentStorage implementation from DOSSIER_STORAGE ('local',
 * 'gcs' or 'drive'). The GCS client is only built when actually selected, so
 * dev and test never need Google credentials; the Drive backend is harmless
 * to construct (it only reads env) and no-ops when Drive is unconfigured.
 */
final readonly class DocumentStorageFactory
{
    public function __construct(
        private LocalDocumentStorage $local,
        private \App\Shared\Gcs\GcsBucketFactory $buckets,
        private DriveDocumentStorage $drive,
        #[Autowire(env: 'DOSSIER_STORAGE')]
        private string $driver = 'local',
    ) {
    }

    public function create(): DocumentStorage
    {
        return match ($this->driver) {
            'local' => $this->local,
            'gcs' => $this->createGcs(),
            'drive' => $this->drive,
            default => throw new \InvalidArgumentException(sprintf('Unknown DOSSIER_STORAGE driver "%s" (use "local", "gcs" or "drive").', $this->driver)),
        };
    }

    private function createGcs(): GcsDocumentStorage
    {
        return new GcsDocumentStorage($this->buckets->bucket());
    }
}
