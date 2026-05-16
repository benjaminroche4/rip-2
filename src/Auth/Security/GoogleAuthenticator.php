<?php

namespace App\Auth\Security;

use App\Auth\Domain\Language;
use App\Auth\Entity\User;
use App\Auth\Repository\EmailVerificationRequestRepository;
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
        private readonly EmailVerificationRequestRepository $verificationRequestRepository,
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
        // Snapshot the locale before the closure runs — `$request` is not captured
        // in the UserBadge callback's scope.
        $locale = $request->getLocale();

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client, $locale): User {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = $googleUser->getId();
                $email = $googleUser->getEmail();

                if (null === $googleId || null === $email) {
                    throw new AuthenticationException('Google returned incomplete profile data.');
                }

                return $this->findOrCreateUser(
                    googleId: $googleId,
                    email: $email,
                    firstName: $googleUser->getFirstName(),
                    lastName: $googleUser->getLastName(),
                    fullName: $googleUser->getName(),
                    avatarUrl: $googleUser->getAvatar(),
                    locale: $locale,
                );
            }),
            [(new RememberMeBadge())->enable()],
        );
    }

    /**
     * Resolves the local User entity backing a successful Google identity:
     *  - matches by googleId first (returning user already linked),
     *  - then by email (legacy classic account adopting Google as second auth method),
     *  - otherwise creates a new User in incomplete-profile state so the completion
     *    gate forces phone / nationality / situation / terms consent on the next request.
     *
     * Public so the find-or-create logic can be unit-tested without spinning up
     * the full OAuth round-trip.
     */
    public function findOrCreateUser(
        string $googleId,
        string $email,
        ?string $firstName,
        ?string $lastName,
        ?string $fullName,
        ?string $avatarUrl,
        ?string $locale = null,
    ): User {
        $user = $this->userRepository->findOneBy(['googleId' => $googleId]);
        if (null !== $user) {
            $this->refreshAvatar($user, $avatarUrl);
            $this->entityManager->flush();

            return $user;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null !== $user) {
            $user->setGoogleId($googleId);
            // Google just validated the email — drop any pending OTP and flip the
            // verified flag so the EmailVerificationListener never sends a returning
            // classic-flow user back to the verification page.
            $user->setVerified(true);
            $this->verificationRequestRepository->removeForUser($user, flush: false);
            $this->refreshAvatar($user, $avatarUrl);
            $this->entityManager->flush();

            return $user;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setGoogleId($googleId);
        $user->setFirstName($firstName ?? $this->firstName($fullName));
        $user->setLastName($lastName ?? $this->lastName($fullName));
        $user->setLanguage(null !== $locale ? Language::tryFrom($locale) : null);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setRoles(['ROLE_USER']);
        // Google supplied identity but not phone / nationality / situation / terms
        // consent — ProfileCompletionListener will gate every request until the
        // user submits CompleteProfileController.
        $user->setProfileComplete(false);
        $user->setVerified(true);
        $this->refreshAvatar($user, $avatarUrl);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
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
