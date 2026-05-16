<?php

namespace App\Tests\Auth;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * After a successful login, the redirect target depends on the user's role:
 *  - ROLE_ADMIN  → admin dashboard at /{_locale}/{adminPrefix}/admin
 *  - everyone else → saved target path or app_home
 *
 * Both branches are wired through App\Auth\Security\LoginSuccessHandler, used
 * by form_login (security.yaml) and reused by GoogleAuthenticator.
 */
final class LoginRedirectTest extends WebTestCase
{
    private const LOGIN_PATH = '/fr/connexion';
    private const SUBMIT_BUTTON = 'Se connecter';
    private const PASSWORD = 'password';
    private const ADMIN_EMAIL = 'login-redirect-admin@example.com';
    private const USER_EMAIL = 'login-redirect-user@example.com';

    private KernelBrowser $client;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        $em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail(self::USER_EMAIL)
            ->setFirstName('Reg')
            ->setLastName('User')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Adm')
            ->setLastName('Strator')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));

        $em->persist($user);
        $em->persist($admin);
        $em->flush();
    }

    public function testIncompleteProfileRedirectsToCompletionPage(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $incomplete = (new User())
            ->setEmail('incomplete-login@example.com')
            ->setFirstName('Inc')
            ->setLastName('Plete')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(false);
        $incomplete->setPassword($hasher->hashPassword($incomplete, self::PASSWORD));
        $em->persist($incomplete);
        $em->flush();

        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => 'incomplete-login@example.com',
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription/profil', $location);
    }

    public function testAdminLoginRedirectsToAdminDashboard(): void
    {
        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::ADMIN_EMAIL,
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/'.$this->adminPrefix.'/admin', $location);
    }

    public function testRegularUserLoginRedirectsToHomeNotAdmin(): void
    {
        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::USER_EMAIL,
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/admin', $location);
        self::assertStringNotContainsString($this->adminPrefix, $location);
    }

    /**
     * Profile-completion gate has priority over the email-verification gate so
     * the funnel order (profile → verify) is enforced regardless of which gate
     * the user is missing. Otherwise a Google user (isVerified=true but
     * profile=false) would risk being sent to the wrong step.
     */
    public function testProfileIncompleteWinsOverUnverifiedEmail(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $partial = (new User())
            ->setEmail('partial-login@example.com')
            ->setFirstName('Par')
            ->setLastName('Tial')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(false);
        $partial->setPassword($hasher->hashPassword($partial, self::PASSWORD));
        $em->persist($partial);
        $em->flush();

        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => 'partial-login@example.com',
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription/profil', $location);
        self::assertStringNotContainsString('/inscription/verification', $location);
    }

    public function testUnverifiedEmailRedirectsToVerifyCode(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $unverified = (new User())
            ->setEmail('unverified-login@example.com')
            ->setFirstName('Un')
            ->setLastName('Verified')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true);
        // isVerified defaults to false — the user completed the profile step
        // but never typed the 6-digit OTP from their confirmation email.
        $unverified->setPassword($hasher->hashPassword($unverified, self::PASSWORD));
        $em->persist($unverified);
        $em->flush();

        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => 'unverified-login@example.com',
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription/verification', $location);
    }

    /**
     * Once logged in, an unverified user trying to browse anywhere else gets
     * bounced by EmailVerificationListener — the gate must hold across requests,
     * not just at login time.
     */
    public function testUnverifiedUserIsBouncedFromOtherPages(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $unverified = (new User())
            ->setEmail('bounce-test@example.com')
            ->setFirstName('Bo')
            ->setLastName('Unce')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true);
        $unverified->setPassword($hasher->hashPassword($unverified, self::PASSWORD));
        $em->persist($unverified);
        $em->flush();

        $this->client->loginUser($unverified);

        // The home page must redirect to the verify gate, not render normally.
        $this->client->request('GET', '/fr');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription/verification', $location);
    }
}
