<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\Attribute\AllowIncompleteProfile;
use App\Auth\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Hard gate keeping users with isProfileComplete=false stuck on the profile-
 * completion page. Email verification (isVerified) is intentionally NOT gated
 * here — the OTP redirect is one-shot at login (LoginSuccessHandler) so the
 * user can browse the site even before clicking the email code. Profile data
 * (phone / nationality / situation / terms consent) is treated as mandatory
 * because the agents need it to follow up on every lead.
 *
 * Force-redirects every main request from an incomplete-profile user to
 * app_register_complete, unless the resolved controller (class or method)
 * carries the AllowIncompleteProfile attribute.
 *
 * Runs on kernel.controller — the opt-out attribute is read first so opt-out
 * routes never touch Security::getUser() (which would force a session start
 * and flip Cache-Control to private on otherwise cacheable routes like
 * /avatars).
 */
final readonly class ProfileCompletionListener
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
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

        if ($this->controllerAllowsIncompleteProfile($event)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isProfileComplete()) {
            return;
        }

        $this->logger->info('Profile incomplete, redirecting to completion page', [
            'user_id' => $user->getId(),
            'route' => $event->getRequest()->attributes->get('_route'),
        ]);

        $url = $this->urlGenerator->generate('app_register_complete', [
            '_locale' => $event->getRequest()->getLocale(),
        ]);
        $event->setController(fn () => new RedirectResponse($url));
    }

    private function controllerAllowsIncompleteProfile(ControllerEvent $event): bool
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

        if ([] !== $reflection->getAttributes(AllowIncompleteProfile::class)) {
            return true;
        }

        $declaringClass = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass() : null;

        return null !== $declaringClass
            && [] !== $declaringClass->getAttributes(AllowIncompleteProfile::class);
    }
}
