<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gate that keeps users with isProfileComplete=false stuck on the profile-completion
 * page (typically reached after a Google sign-in that created the account without
 * phone / nationality / terms consent).
 *
 * Force-redirects every main request from such a user to app_register_complete,
 * except for a small allowlist (the completion page itself, logout, avatar
 * serving, and the profiler/asset paths already excluded from the firewall).
 *
 * Runs after the firewall (priority < 8) so the token storage is populated.
 */
final readonly class ProfileCompletionListener
{
    private const ALLOWED_ROUTES = [
        'app_register_complete',
        'app_logout',
        'app_avatar',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(RequestEvent::class, priority: 4)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isProfileComplete()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');

        // Profiler / dev toolbar paths start with `_` and are already disabled
        // by the security firewall — they never carry a user, but guard anyway.
        if ('' === $route || str_starts_with($route, '_')) {
            return;
        }

        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_register_complete', [
                '_locale' => $request->getLocale(),
            ]),
        ));
    }
}
