<?php

namespace App\Tests\Auth;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Profile-completion gate reached after a Google sign-in (or anyone whose
 * isProfileComplete flag is false). Covers:
 *  - anonymous → redirect to login
 *  - auth + complete → redirect to home (gate is invisible)
 *  - auth + incomplete → page renders with the Account step fields
 *  - valid submit → persists phone + nationality + flips isProfileComplete
 *  - invalid submit → 422, no DB change
 *  - listener: incomplete user hitting any other route is force-redirected here
 *  - listener: incomplete user can still reach logout (no soft-lock)
 */
final class CompleteProfileControllerTest extends WebTestCase
{
    private const COMPLETE_PATH = '/fr/inscription/profil';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $em = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->userRepository = $container->get(UserRepository::class);

        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', self::COMPLETE_PATH);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testCompleteProfileUserIsRedirectedHome(): void
    {
        $user = $this->makeUser('complete@example.com', profileComplete: true);
        $this->client->loginUser($user);

        $this->client->request('GET', self::COMPLETE_PATH);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/inscription/profil', $location);
    }

    public function testIncompleteProfileUserSeesTheForm(): void
    {
        $user = $this->makeUser('incomplete@example.com', profileComplete: false);
        $this->client->loginUser($user);

        $this->client->request('GET', self::COMPLETE_PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="account_step[phoneNumber]"]');
        self::assertSelectorExists('select[name="account_step[nationality]"]');
        self::assertSelectorExists('input[name="account_step[acceptTerms]"]');
    }

    public function testValidSubmitPersistsProfileAndFlipsFlag(): void
    {
        $user = $this->makeUser('incomplete@example.com', profileComplete: false);
        $this->client->loginUser($user);

        $this->client->request('GET', self::COMPLETE_PATH);
        $csrf = $this->client->getCrawler()->filter('input[name="account_step[_token]"]')->attr('value');

        $this->client->request('POST', self::COMPLETE_PATH, [
            'account_step' => [
                '_token' => $csrf,
                'phoneNumber' => '+33612345678',
                'nationality' => 'FR',
                'acceptTerms' => '1',
            ],
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/inscription/profil', $location);

        $this->em->clear();
        $saved = $this->userRepository->findOneBy(['email' => 'incomplete@example.com']);
        self::assertNotNull($saved);
        self::assertTrue($saved->isProfileComplete());
        self::assertSame('+33612345678', $saved->getPhoneNumber());
        self::assertSame('FR', $saved->getNationality());
    }

    public function testInvalidSubmitReturns422AndKeepsProfileIncomplete(): void
    {
        $user = $this->makeUser('incomplete@example.com', profileComplete: false);
        $this->client->loginUser($user);

        $this->client->request('GET', self::COMPLETE_PATH);
        $csrf = $this->client->getCrawler()->filter('input[name="account_step[_token]"]')->attr('value');

        $this->client->request('POST', self::COMPLETE_PATH, [
            'account_step' => [
                '_token' => $csrf,
                'phoneNumber' => '',
                'nationality' => '',
                'acceptTerms' => '0',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $saved = $this->userRepository->findOneBy(['email' => 'incomplete@example.com']);
        self::assertNotNull($saved);
        self::assertFalse($saved->isProfileComplete());
    }

    public function testListenerForcesIncompleteUserToCompletionPage(): void
    {
        $user = $this->makeUser('incomplete@example.com', profileComplete: false);
        $this->client->loginUser($user);

        // Hits the register page, which would normally bounce an authenticated
        // user to home — the listener short-circuits it to the completion page.
        $this->client->request('GET', '/fr/inscription');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription/profil', $location);
    }

    public function testListenerLeavesCompleteUserAlone(): void
    {
        $user = $this->makeUser('complete@example.com', profileComplete: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/fr/inscription');

        // Complete users still get redirected by the register controller (because
        // they are already authenticated), but NOT to the profile-completion page.
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/inscription/profil', $location);
    }

    public function testIncompleteUserCanStillLogout(): void
    {
        $user = $this->makeUser('incomplete@example.com', profileComplete: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/fr/deconnexion');

        // Logout is intercepted by the firewall and redirects out — not a 302 to
        // the completion page. The key assertion is that we are NOT redirected
        // back to /inscription/profil.
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/inscription/profil', $location);
    }

    private function makeUser(string $email, bool $profileComplete): User
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Alice')
            ->setLastName('Martin')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete($profileComplete);
        $user->setPassword($hasher->hashPassword($user, 'V3ryStr0ng!Passw0rd2026'));

        if ($profileComplete) {
            $user->setPhoneNumber('+33611111111');
            $user->setNationality('FR');
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
