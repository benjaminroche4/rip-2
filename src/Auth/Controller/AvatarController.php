<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Attribute\AllowIncompleteProfile;
use App\Auth\Attribute\AllowUnverifiedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams locally-stored avatars from var/uploads/avatars/.
 *
 * Why a controller instead of dropping files in /public/?
 *  - CLAUDE.md upload policy keeps user-derived files outside /public/
 *  - Centralized cache headers (immutable + 1 year) thanks to UUID filenames
 *    that are content-addressable: the file at <uuid>.webp never changes,
 *    a new avatar gets a new UUID, so we can serve with `immutable`.
 *  - Single point to add ACL later if avatars become non-public.
 */
#[AllowIncompleteProfile]
#[AllowUnverifiedEmail]
final class AvatarController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/avatars')]
        private readonly string $storageDir,
    ) {
    }

    #[Route(
        '/avatars/{filename}',
        name: 'app_avatar',
        requirements: ['filename' => '[0-9a-f-]{36}\.webp'],
        methods: ['GET'],
    )]
    public function __invoke(string $filename, Request $request): Response
    {
        $path = $this->storageDir.'/'.$filename;
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(31_536_000);            // 1 year — content is UUID-addressed, immutable
        $response->setSharedMaxAge(31_536_000);
        $response->headers->set('Cache-Control', $response->headers->get('Cache-Control').', immutable');
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->isNotModified($request);          // sends 304 when ETag/IMS match

        return $response;
    }
}
