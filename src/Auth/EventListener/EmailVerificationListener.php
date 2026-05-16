<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\Attribute\AllowUnverifiedEmail;
use App\Auth\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Hard gate keeping users with isVerified=false stuck on the OTP verification
 * page. An account that hasn't proven ownership of its email cannot interact
 * with the rest of the site — agents must not be able to be contacted by
 * unconfirmed identities.
 *
 * Layered with {@see ProfileCompletionListener}: if the user is also missing
 * their profile (isProfileComplete=false), this listener defers — the profile
 * gate wins so the funnel order (profile → verify) is enforced deterministically
 * regardless of listener invocation order.
 *
 * The session marker `register_check_email` is (re)written here so the verify
 * page can identify the pending user even when the user reaches the gate from
 * a fresh login (the marker set by RegisterController is long gone by then).
 *
 * The opt-out attribute is read BEFORE touching Security::getUser() — calling
 * getUser() forces the firewall (and therefore the session) to start, which
 * flips Cache-Control from `public` to `private` in the kernel.response phase.
 * Cacheable opt-out routes like /avatars must never trigger that.
 */
final readonly class EmailVerificationListener
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(ControllerEvent::class)]
    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->controllerAllowsUnverifiedEmail($event)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        // Profile completion takes priority — let ProfileCompletionListener
        // redirect to step 2 first; we only kick in once the profile is filled.
        if (!$user->isProfileComplete()) {
            return;
        }

        $this->logger->info('Email not verified, redirecting to verification page', [
            'user_id' => $user->getId(),
            'route' => $event->getRequest()->attributes->get('_route'),
        ]);

        // Re-seed the session marker the verify page reads to identify the
        // pending user — needed when the user reaches this gate from a fresh
        // login (their RegisterController-set marker is long gone).
        $this->requestStack->getSession()->set('register_check_email', $user->getUserIdentifier());

        $url = $this->urlGenerator->generate('app_register_verify_code', [
            '_locale' => $event->getRequest()->getLocale(),
        ]);
        $event->setController(fn () => new RedirectResponse($url));
    }

    private function controllerAllowsUnverifiedEmail(ControllerEvent $event): bool
    {
        $controller = $event->getController();

        if (\is_array($controller)) {
            $reflection = new \ReflectionMethod($controller[0], $controller[1]);
        } elseif ($controller instanceof \Closure) {
            $reflection = new \ReflectionFunction($controller);
        } elseif (\is_object($controller) && method_exists($controller, '__invoke')) {
            $reflection = new \ReflectionMethod($controller, '__invoke');
        } else {
            return false;
        }

        if ([] !== $reflection->getAttributes(AllowUnverifiedEmail::class)) {
            return true;
        }

        $declaringClass = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass() : null;

        return null !== $declaringClass
            && [] !== $declaringClass->getAttributes(AllowUnverifiedEmail::class);
    }
}
