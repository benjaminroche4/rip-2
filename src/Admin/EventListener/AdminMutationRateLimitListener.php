<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Defense-in-depth limiter on Live admin mutations. The admin area already
 * requires ROLE_ADMIN, but if a session ever gets hijacked or an admin
 * account leaks, this caps the blast radius (60 mutations / minute / IP).
 *
 * Targets only `ux_live_component` POST/PATCH/DELETE requests whose
 * component name starts with `Admin:` — read-only admin GET routes and
 * non-admin Live components are left alone.
 */
#[AsEventListener(event: 'kernel.request')]
final readonly class AdminMutationRateLimitListener
{
    private const MUTATING_METHODS = ['POST', 'PATCH', 'DELETE'];

    public function __construct(
        #[Autowire(service: 'limiter.admin_mutation')]
        private RateLimiterFactory $adminMutationLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!\in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return;
        }

        if ('ux_live_component' !== $request->attributes->get('_route')) {
            return;
        }

        $component = (string) $request->attributes->get('_live_component', '');
        if (!str_starts_with($component, 'Admin:')) {
            return;
        }

        $limiter = $this->adminMutationLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
