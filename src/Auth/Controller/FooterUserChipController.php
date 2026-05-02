<?php

namespace App\Auth\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ESI fragment that renders the auth-aware login/logout link in the footer.
 *
 * Extracted as a fragment so the rest of the page (footer + content) can be
 * cached publicly — only this small block depends on the user session.
 *
 * Used via {{ render_esi(controller(...)) }} from the footer template. With a
 * reverse-proxy that supports ESI in front (Symfony HTTP cache, Varnish,
 * Cloudflare Workers), the rest of the page is served from cache and only
 * this fragment is computed per-user. Without a proxy, Symfony falls back to
 * inline rendering — same output, no behavior change.
 */
final class FooterUserChipController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route('/_fragment/footer/user-chip', name: 'fragment_footer_user_chip', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $response = $this->render('components/Layout/Footer/UserChip.html.twig', [
            'currentRoute' => $request->query->get('currentRoute', ''),
            'adminPrefix' => $this->adminPathPrefix,
        ]);

        // Per-session response, never cache publicly.
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
