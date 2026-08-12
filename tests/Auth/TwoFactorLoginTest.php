<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Login interception contract: an account with TOTP enabled lands on the
 * 2FA challenge after a valid password and reaches nothing else until a
 * valid code (or single-use recovery code) is submitted. Accounts without
 * 2FA keep the exact same login flow as before.
 */
final class TwoFactorLoginTest extends WebTestCase
{
    private const PASSWORD = 'current-password';
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@two-factor-login-test.local')->execute();
    }

    public function testAccountWithoutTwoFactorLogsInDirectly(): void
    {
        // 2FA is opt-in: a login without it reaches the BO directly.
        $this->persistUser(withTotp: false);
        $this->submitLogin(self::PASSWORD);

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        self::assertResponseIsSuccessful();
    }

    public function testAccountWithTwoFactorIsHeldOnTheChallenge(): void
    {
        $this->persistUser(withTotp: true);

        $this->submitLogin(self::PASSWORD);

        // Semi-authenticated: any protected page redirects to the challenge.
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/connexion/2fa', (string) $this->client->getResponse()->headers->get('Location'));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="2fa-form"]');

        // Wrong code: back on the challenge with an error.
        $this->submitCode($crawler, '000000');
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="2fa-error"]');

        // Valid TOTP code: fully authenticated.
        $this->submitCode($crawler, TOTP::createFromSecret(self::TOTP_SECRET)->now());
        $this->client->followRedirect();

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        self::assertResponseIsSuccessful();

        // The device is trusted automatically after a validated code (no
        // shared computers in the team): the cookie spares the next logins.
        self::assertNotNull(
            $this->client->getCookieJar()->get('rip_trusted_device'),
            'A validated code marks the device as trusted for 30 days.',
        );
    }

    public function testRecoveryCodePassesTheChallengeOnceOnly(): void
    {
        $user = $this->persistUser(withTotp: true);

        $this->submitLogin(self::PASSWORD);
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        $crawler = $this->client->followRedirect();

        // The recovery code authenticates...
        $this->submitCode($crawler, '12345678');
        $this->client->followRedirect();
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        self::assertResponseIsSuccessful();

        // ...and is burned: it does not pass a second login.
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $fresh = $em->find(User::class, $user->getId());
        self::assertFalse($fresh->isBackupCode('12345678'));
        self::assertSame(0, $fresh->getRemainingBackupCodeCount());
    }

    public function testAfterTheChallengeStaffLandsOnTheBackOfficeNotTheHomepage(): void
    {
        $user = $this->persistUser(withTotp: true);
        $user->setRoles(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $this->em->flush();

        $this->submitLogin(self::PASSWORD);
        // Reach the challenge like the other tests (a protected page bounces
        // the half-authenticated token to /connexion/2fa).
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/mon-profil');
        $crawler = $this->client->followRedirect();
        $this->submitCode($crawler, TOTP::createFromSecret(self::TOTP_SECRET)->now());

        // The post-2FA redirect goes to the back-office, not the homepage.
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/admin', $location);
        self::assertDoesNotMatchRegularExpression('~/(fr|en)/?$~', $location, 'Not the homepage.');
    }

    public function testWrongPasswordNeverReachesTheChallenge(): void
    {
        $this->persistUser(withTotp: true);

        $this->submitLogin('wrong-password');

        self::assertResponseStatusCodeSame(302);
        self::assertStringNotContainsString('2fa', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function submitLogin(string $password): void
    {
        $crawler = $this->client->request('GET', '/fr/connexion');
        $form = $crawler->filter('form[data-controller="inline-validation"]')->form([
            '_username' => 'staff@two-factor-login-test.local',
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }

    private function submitCode(\Symfony\Component\DomCrawler\Crawler $crawler, string $code): void
    {
        $form = $crawler->filter('[data-testid="2fa-form"]')->form(['_auth_code' => $code]);
        $this->client->submit($form);
    }

    private function persistUser(bool $withTotp): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail('staff@two-factor-login-test.local')
            ->setFirstName('Sam')->setLastName('Staff')
            ->setRoles(['ROLE_STAFF'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        if ($withTotp) {
            $user->setPlainTotpSecret(self::TOTP_SECRET);
            $user->setPlainBackupCodes(['12345678']);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
