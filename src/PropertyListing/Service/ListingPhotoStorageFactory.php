<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Picks the ListingPhotoStorage from LISTING_STORAGE ('local' or 'gcs'),
 * reusing the same bucket and service-account key as the other GCS assets.
 */
final readonly class ListingPhotoStorageFactory
{
    public function __construct(
        private LocalListingPhotoStorage $local,
        private \App\Shared\Gcs\GcsBucketFactory $buckets,
        private SluggerInterface $slugger,
        #[Autowire(env: 'LISTING_STORAGE')]
        private string $driver = 'local',
    ) {
    }

    public function create(): ListingPhotoStorage
    {
        return match ($this->driver) {
            'local' => $this->local,
            'gcs' => $this->createGcs(),
            default => throw new \InvalidArgumentException(sprintf('Unknown LISTING_STORAGE driver "%s" (use "local" or "gcs").', $this->driver)),
        };
    }

    private function createGcs(): GcsListingPhotoStorage
    {
        return new GcsListingPhotoStorage($this->buckets->bucket(), $this->slugger);
    }
}
