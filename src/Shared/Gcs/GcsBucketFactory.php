<?php

declare(strict_types=1);

namespace App\Shared\Gcs;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single place that builds a configured GCS Bucket for every storage driver
 * (avatars, dossier documents, listing/visit photos). Centralizes the two
 * things that must never be forgotten on a shared host:
 *
 *  - a request timeout + a single retry, so a slow/unavailable GCS pins a
 *    PHP worker for seconds, not until max_execution_time (o2switch has no
 *    async workers to absorb it);
 *  - the credential form: GCS_KEY_FILE is a path OR the service-account JSON
 *    base64-encoded (same convention as GOOGLE_CALENDAR_KEY_FILE), handy when
 *    dropping a file outside the webroot is impractical.
 *
 * The built Bucket is memoized so several drivers in `gcs` mode share one
 * authenticated client instead of re-reading the key and re-authenticating.
 */
final class GcsBucketFactory
{
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /** @var array<string, Bucket> */
    private array $buckets = [];

    public function __construct(
        #[Autowire(env: 'GCS_BUCKET')]
        private readonly string $bucket = '',
        #[Autowire(env: 'GCS_KEY_FILE')]
        private readonly string $keyFile = '',
    ) {
    }

    public function bucket(): Bucket
    {
        if ('' === trim($this->bucket)) {
            throw new \InvalidArgumentException('GCS storage requires the GCS_BUCKET env var.');
        }

        return $this->buckets[$this->bucket] ??= $this->build();
    }

    private function build(): Bucket
    {
        $options = [
            // Fail fast: a hung GCS call must not hold a worker forever.
            'requestTimeout' => self::REQUEST_TIMEOUT_SECONDS,
            'retries' => 1,
        ];

        $key = trim($this->keyFile);
        if ('' !== $key) {
            $decoded = base64_decode($key, true);
            if (false !== $decoded && json_validate($decoded)) {
                // Base64-encoded service-account JSON (never written to disk,
                // never logged).
                $options['keyFile'] = json_decode($decoded, true);
            } else {
                $options['keyFilePath'] = $key;
            }
        }

        return (new StorageClient($options))->bucket($this->bucket);
    }
}
