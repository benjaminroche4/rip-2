<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tokened endpoint that purges the whole LiteSpeed page cache, meant for
 * the deploy script (`make purge-cache`) and for a future Sanity publish
 * webhook. The purge itself is just a response header: LiteSpeed reads
 * "X-LiteSpeed-Purge" and drops the matching entries.
 */
final class CachePurgeController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::string:CACHE_PURGE_TOKEN)%')]
        private readonly ?string $purgeToken,
    ) {
    }

    #[Route('/_cache/purge', name: 'app_cache_purge', methods: ['GET'])]
    public function purge(Request $request): Response
    {
        $token = $request->query->getString('token');
        if (null === $this->purgeToken || '' === $this->purgeToken || !hash_equals($this->purgeToken, $token)) {
            throw $this->createNotFoundException();
        }

        $tag = $request->query->getString('tag');
        $purge = '' !== $tag && preg_match('/^[a-z0-9_-]+$/', $tag) ? 'tag='.$tag : '*';

        return new Response('purged: '.$purge, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'X-LiteSpeed-Purge' => $purge,
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ]);
    }
}
