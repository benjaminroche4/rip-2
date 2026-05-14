<?php

namespace App\Auth\Security;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use App\Auth\Service\AvatarDownloader;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface, InteractiveAuthenticatorInterface
{
    public function isInteractive(): bool
    {
        return true;
    }

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoginSuccessHandler $loginSuccessHandler,
        private readonly AvatarDownloader $avatarDownloader,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'app_google_login_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client): User {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = $googleUser->getId();
                $email = $googleUser->getEmail();

                if (null === $googleId || null === $email) {
                    throw new AuthenticationException('Google returned incomplete profile data.');
                }

                $avatarUrl = $googleUser->getAvatar();

                $user = $this->userRepository->findOneBy(['googleId' => $googleId]);
                if (null !== $user) {
                    $this->refreshAvatar($user, $avatarUrl);
                    $this->entityManager->flush();

                    return $user;
                }

                $user = $this->userRepository->findOneBy(['email' => $email]);
                if (null !== $user) {
                    $user->setGoogleId($googleId);
                    $this->refreshAvatar($user, $avatarUrl);
                    $this->entityManager->flush();

                    return $user;
                }

                $user = new User();
                $user->setEmail($email);
                $user->setGoogleId($googleId);
                $user->setFirstName($googleUser->getFirstName() ?? $this->firstName($googleUser->getName()));
                $user->setLastName($googleUser->getLastName() ?? $this->lastName($googleUser->getName()));
                $user->setCreatedAt(new \DateTimeImmutable());
                $user->setRoles(['ROLE_USER']);
                // Google supplied identity but not phone / nationality / terms consent —
                // ProfileCompletionListener will gate every request until the user
                // submits CompleteProfileController.
                $user->setProfileComplete(false);
                $user->setVerified(true);
                $this->refreshAvatar($user, $avatarUrl);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return $user;
            }),
            [(new RememberMeBadge())->enable()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->loginSuccessHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('error', 'Authentication with Google failed. Please try again.');

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    private function firstName(?string $fullName): string
    {
        if (null === $fullName) {
            return '';
        }

        $parts = explode(' ', trim($fullName), 2);

        return $parts[0] ?? '';
    }

    private function lastName(?string $fullName): string
    {
        if (null === $fullName) {
            return '';
        }

        $parts = explode(' ', trim($fullName), 2);

        return $parts[1] ?? '';
    }

    /**
     * Pulls the Google account picture into local storage, drops the previous
     * file if any, updates the user. Silent on failure — a missing avatar is
     * never a reason to break the login flow.
     */
    private function refreshAvatar(User $user, ?string $avatarUrl): void
    {
        if (null === $avatarUrl || '' === $avatarUrl) {
            return;
        }

        $newFilename = $this->avatarDownloader->downloadAndStore($avatarUrl);
        if (null === $newFilename) {
            return;
        }

        $previous = $user->getAvatarFilename();
        $user->setAvatarFilename($newFilename);

        if (null !== $previous && $previous !== $newFilename) {
            $this->avatarDownloader->delete($previous);
        }
    }
}
