<?php

namespace App\Shared\Sanity;

use Sanity\Client as SanityClient;

class SanityService
{
    private SanityClient $client;

    public function __construct(
        string $sanityProjectId,
        string $sanityDataset,
        string $sanityApiVersion,
        string $sanityToken,
        bool $sanityUseCdn,
    ) {
        $this->client = new SanityClient([
            'projectId' => $sanityProjectId,
            'dataset' => $sanityDataset,
            'useCdn' => $sanityUseCdn,
            'apiVersion' => $sanityApiVersion,
            'token' => $sanityToken,
        ]);
    }

    public function getClient(): SanityClient
    {
        return $this->client;
    }

    public function query(string $query, array $params = []): mixed
    {
        return $this->client->fetch($query, $params);
    }
}
