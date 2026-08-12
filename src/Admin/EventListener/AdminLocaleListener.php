<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Auth\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The back-office speaks the language stored on the account: opening any
 * BO page in another locale redirects to the same page in the user's own
 * one. Changing the language (on "Mon profil" or from a user's file) is
 * therefore enough, the next page load follows.
 *
 * Scoped to the admin space on purpose: the public site keeps its
 * URL-driven locale (hreflang, shared links, visitors switching manually).
 *
 * Priority 4: after the firewall (8) so the token is available, and after
 * the router (32) so the route and its params are resolved.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 4)]
final readonly class AdminLocaleListener
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Safe navigation only: redirecting a POST would drop its body, and
        // sub-requests (ESI, forwards) must not bounce.
        if (!$event->isMainRequest() || !$request->isMethod('GET')) {
            return;
        }

        if (!preg_match('#^/(fr|en)/'.preg_quote($this->adminPathPrefix, '#').'/admin(?:$|/)#', $request->getPathInfo())) {
            return;
        }

        $user = $this->security->getUser();
        $language = $user instanceof User ? $user->getLanguage() : null;
        if (null === $language || $language->value === $request->getLocale()) {
            return;
        }

        $route = $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params', []);
        if (!\is_string($route) || '' === $route || !\is_array($params) || !isset($params['_locale'])) {
            return;
        }

        // Regenerated from the route, not string-patched: admin paths are
        // localized (/dossiers vs /files), the generator picks the right one.
        $params['_locale'] = $language->value;
        $url = $this->urlGenerator->generate($route, $params);
        $query = $request->getQueryString();

        $event->setResponse(new RedirectResponse(null !== $query ? $url.'?'.$query : $url));
    }
}
