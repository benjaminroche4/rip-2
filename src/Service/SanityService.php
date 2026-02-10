<?php

namespace App\Service;

use Sanity\Client as SanityClient;

class SanityService
{
    private SanityClient $client;

    public function __construct(
        string $sanityProjectId,
        string $sanityDataset,
        string $sanityApiVersion,
        string $sanityToken,
    ) {
        $this->client = new SanityClient([
            'projectId' => $sanityProjectId,
            'dataset' => $sanityDataset,
            'useCdn' => false,
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