<?php

namespace App\Cms\Controller\Webhook;

use App\Blog\Repository\BlogRepository;
use App\Marketplace\Repository\PropertyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Endpoint called by Sanity Studio when a document is created/updated/deleted.
 * Validates the HMAC signature, then invalidates cache tags accordingly.
 *
 * Configure in Sanity Studio (manage.sanity.io → API → Webhooks):
 *   - URL: https://relocation-in-paris.fr/_webhook/sanity
 *   - Trigger: Create / Update / Delete
 *   - Filter: _type in ["property", "propertyType", "blog", "category"]
 *   - HTTP method: POST
 *   - API version: v2021-03-25 (or later)
 *   - Secret: <random 32+ chars> (must match WEBHOOK_SANITY_SECRET in .env)
 */
final class SanityWebhookController extends AbstractController
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        #[Autowire(env: 'WEBHOOK_SANITY_SECRET')]
        private readonly string $secret,
    ) {}

    #[Route('/_webhook/sanity', name: 'webhook_sanity', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signatureHeader = $request->headers->get('sanity-webhook-signature', '');

        if (!$this->verifySignature($payload, $signatureHeader)) {
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        $body = json_decode($payload, true);
        $type = is_array($body) ? ($body['_type'] ?? null) : null;

        $invalidatedTags = [];

        if (in_array($type, ['property', 'propertyType'], true)) {
            $this->cache->invalidateTags([PropertyRepository::CACHE_TAG]);
            $invalidatedTags[] = PropertyRepository::CACHE_TAG;
        }

        if (in_array($type, ['blog', 'category'], true)) {
            $this->cache->invalidateTags([BlogRepository::CACHE_TAG]);
            $invalidatedTags[] = BlogRepository::CACHE_TAG;
        }

        // Type inconnu/non filtré → on flush par sécurité les deux tags.
        if (empty($invalidatedTags)) {
            $this->cache->invalidateTags([PropertyRepository::CACHE_TAG, BlogRepository::CACHE_TAG]);
            $invalidatedTags = [PropertyRepository::CACHE_TAG, BlogRepository::CACHE_TAG];
        }

        return new JsonResponse(['ok' => true, 'invalidated' => $invalidatedTags]);
    }

    /**
     * Sanity signs payloads using HMAC-SHA256, format: "t=<timestamp>,v1=<hash>".
     * See https://www.sanity.io/docs/webhooks#authenticating-webhook-requests
     */
    private function verifySignature(string $payload, string $signatureHeader): bool
    {
        if ($signatureHeader === '' || $this->secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || $signature === null) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $signedPayload, $this->secret, true)), '+/', '-_'), '=');

        return hash_equals($expected, $signature);
    }
}
