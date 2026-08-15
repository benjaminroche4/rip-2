<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Attribute\AllowIncompleteProfile;
use App\Auth\Attribute\AllowUnverifiedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams user avatars through the app, whatever the backend (disk in dev,
 * a private GCS bucket in prod): object URLs are never public.
 *
 * Why a controller instead of dropping files in /public/?
 *  - CLAUDE.md upload policy keeps user-derived files outside /public/
 *  - Centralized cache headers (immutable + 1 year) thanks to UUID filenames
 *    that are content-addressable: the file at <uuid>.webp never changes,
 *    a new avatar gets a new UUID, so we can serve with `immutable`.
 *  - The GCS bucket stays fully private (same posture as dossier documents).
 */
#[AllowIncompleteProfile]
#[AllowUnverifiedEmail]
final class AvatarController extends AbstractController
{
    public function __construct(
        private readonly \App\Auth\Storage\AvatarStorage $storage,
    ) {
    }

    #[Route(
        '/avatars/{path}',
        name: 'app_avatar',
        requirements: ['path' => '(users|agencies|agents)/[0-9A-Za-z]+/(avatar|logo)/[0-9a-f-]{36}\.webp'],
        methods: ['GET'],
    )]
    public function __invoke(string $path, Request $request): Response
    {
        // ETag from the immutable UUID (last path segment): a 304 spares the
        // backend read entirely (no disk hit, no GCS download) on revalidation.
        $etag = '"'.substr(basename($path), 0, 36).'"';
        $response = new StreamedResponse();
        $response->setEtag($etag);
        // The 2FA listeners read the session on every request, which would
        // otherwise trip Symfony's auto-downgrade to `private`: an immutable
        // UUID-addressed avatar is deliberately public.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPublic();
        $response->setMaxAge(31_536_000);            // 1 year — content is UUID-addressed, immutable
        $response->setSharedMaxAge(31_536_000);
        $response->headers->set('Cache-Control', (string) $response->headers->get('Cache-Control').', immutable');
        $response->headers->set('Content-Type', 'image/webp');

        if ($response->isNotModified($request)) {
            return $response;                        // 304, no backend read
        }

        try {
            $available = $this->storage->exists($path);
        } catch (\Throwable) {
            // A storage hiccup on a non-critical avatar is a 404, not a 500.
            $available = false;
        }
        if (!$available) {
            throw $this->createNotFoundException();
        }

        $response->setCallback(function () use ($path): void {
            $out = fopen('php://output', 'w');
            $stream = null;
            try {
                $stream = $this->storage->readStream($path);
                stream_copy_to_stream($stream, $out);
            } catch (\Throwable) {
                // Nothing to do mid-stream: the client gets a truncated body.
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
                if (\is_resource($out)) {
                    fclose($out);
                }
            }
        });

        return $response;
    }
}
