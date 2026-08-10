<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use Google\Cloud\Storage\StorageClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the DocumentStorage implementation from DOSSIER_STORAGE ('local' or
 * 'gcs'). The GCS client is only built when actually selected, so dev and
 * test never need Google credentials.
 */
final readonly class DocumentStorageFactory
{
    public function __construct(
        private LocalDocumentStorage $local,
        #[Autowire(env: 'DOSSIER_STORAGE')]
        private string $driver = 'local',
        #[Autowire(env: 'GCS_BUCKET')]
        private string $bucket = '',
        #[Autowire(env: 'GCS_KEY_FILE')]
        private string $keyFile = '',
    ) {
    }

    public function create(): DocumentStorage
    {
        return match ($this->driver) {
            'local' => $this->local,
            'gcs' => $this->createGcs(),
            default => throw new \InvalidArgumentException(sprintf('Unknown DOSSIER_STORAGE driver "%s" (use "local" or "gcs").', $this->driver)),
        };
    }

    private function createGcs(): GcsDocumentStorage
    {
        if ('' === trim($this->bucket)) {
            throw new \InvalidArgumentException('DOSSIER_STORAGE=gcs requires the GCS_BUCKET env var.');
        }

        $options = [];
        if ('' !== trim($this->keyFile)) {
            $options['keyFilePath'] = $this->keyFile;
        }

        return new GcsDocumentStorage((new StorageClient($options))->bucket($this->bucket));
    }
}
