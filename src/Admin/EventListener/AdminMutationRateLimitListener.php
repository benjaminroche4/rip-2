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
 * Targets only `ux_live_component` POST/PATCH/DELETE requests of the
 * back-office components — read-only admin GET routes and public Live
 * components (Marketplace) are left alone.
 */
#[AsEventListener(event: 'kernel.request')]
final readonly class AdminMutationRateLimitListener
{
    private const MUTATING_METHODS = ['POST', 'PATCH', 'DELETE'];

    /**
     * Every back-office component namespace. A new BO context must be added
     * here, otherwise its mutations escape the hijack blast-radius cap.
     */
    private const BO_PREFIXES = ['Admin:', 'Dossier:', 'Visit:', 'RealEstateAgent:'];

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
        if (!array_any(self::BO_PREFIXES, static fn (string $prefix): bool => str_starts_with($component, $prefix))) {
            return;
        }

        $limiter = $this->adminMutationLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
