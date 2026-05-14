<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Routes ROLE_ADMIN users straight to the admin dashboard upon successful
 * authentication. Other users keep Symfony's default behavior (target path
 * stored in session before login, or the homepage as a fallback).
 *
 * Wired into form_login (security.yaml: form_login.success_handler) and
 * called from GoogleAuthenticator::onAuthenticationSuccess so both flows
 * apply the same routing rule.
 */
final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        $userLanguage = $user instanceof User ? $user->getLanguage() : null;
        $locale = null !== $userLanguage ? $userLanguage->value : $request->getLocale();

        // Profile incomplete (typically a fresh Google sign-in that hasn't yet provided
        // phone / nationality / terms consent) — funnel them to the completion gate
        // directly, before any admin / target-path routing.
        if ($user instanceof User && !$user->isProfileComplete()) {
            return new RedirectResponse($this->urlGenerator->generate('app_register_complete', [
                '_locale' => $locale,
            ]));
        }

        if (\in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return new RedirectResponse($this->urlGenerator->generate('admin_dashboard', [
                '_locale' => $locale,
                'adminPrefix' => $this->adminPathPrefix,
            ]));
        }

        $firewall = 'main';
        $session = $request->hasSession() ? $request->getSession() : null;
        if (null !== $session) {
            $targetPath = $this->getTargetPath($session, $firewall);
            if (\is_string($targetPath) && '' !== $targetPath) {
                $this->removeTargetPath($session, $firewall);

                return new RedirectResponse($targetPath);
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home', ['_locale' => $locale]));
    }
}
