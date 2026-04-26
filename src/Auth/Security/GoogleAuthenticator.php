<?php

namespace App\Auth\Security;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
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
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
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

                $user = $this->userRepository->findOneBy(['googleId' => $googleId]);
                if (null !== $user) {
                    return $user;
                }

                $user = $this->userRepository->findOneBy(['email' => $email]);
                if (null !== $user) {
                    $user->setGoogleId($googleId);
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

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_home'));
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
}
