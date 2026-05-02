<?php

namespace App\Tests;

use App\Auth\Domain\Language;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the form-login flow on the localized /fr/connexion route.
 *
 * Asserts the three branches that matter:
 *  - submitting an unknown email rejects with an alert
 *  - submitting a known email + wrong password rejects with the same alert
 *    (we don't reveal whether the email exists, intentionally)
 *  - submitting valid credentials redirects away from /fr/connexion
 */
final class LoginControllerTest extends WebTestCase
{
    private const LOGIN_PATH = '/fr/connexion';
    private const SUBMIT_BUTTON = 'Se connecter';
    private const TEST_EMAIL = 'login-test@example.com';
    private const TEST_PASSWORD = 'password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        // Wipe + ensure a User exists with the required NOT NULL columns.
        // Foreign-keyed children (ResetPasswordRequest) are removed first so
        // doctrine can drop the parent row without integrity errors.
        $em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail(self::TEST_EMAIL)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setCreatedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, self::TEST_PASSWORD));

        $em->persist($user);
        $em->flush();
    }

    public function testRejectsUnknownEmail(): void
    {
        $this->client->request('GET', self::LOGIN_PATH);
        self::assertResponseIsSuccessful();

        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => 'does-not-exist@example.com',
            '_password' => self::TEST_PASSWORD,
        ]);

        // form_login redirects back to the login path on failure.
        self::assertResponseRedirects(self::LOGIN_PATH);
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
    }

    public function testRejectsKnownEmailWithBadPassword(): void
    {
        $this->client->request('GET', self::LOGIN_PATH);

        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::TEST_EMAIL,
            '_password' => 'wrong-password',
        ]);

        self::assertResponseRedirects(self::LOGIN_PATH);
        $this->client->followRedirect();
        // Same alert shape — we don't leak whether the email is registered.
        self::assertSelectorExists('[role="alert"]');
    }

    public function testValidCredentialsLogIn(): void
    {
        $this->client->request('GET', self::LOGIN_PATH);

        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
        ]);

        // On success, form_login redirects to the saved target path or '/'.
        self::assertResponseStatusCodeSame(302);
        self::assertNotSame(
            self::LOGIN_PATH,
            $this->client->getResponse()->headers->get('Location'),
            'Successful login must NOT redirect back to the login page.',
        );
    }

    public function testInteractiveLoginStampsLastLoginAt(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $beforeUser = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($beforeUser);
        self::assertNull($beforeUser->getLastLoginAt(), 'Fresh user must have lastLoginAt = null.');

        $beforeLogin = new \DateTimeImmutable();

        $this->client->request('GET', self::LOGIN_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
        ]);

        // Reload from the DB, the listener has flushed in another EM identity.
        $em->clear();
        $afterUser = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($afterUser);
        $stamp = $afterUser->getLastLoginAt();
        self::assertNotNull($stamp, 'lastLoginAt must be set after a successful interactive login.');
        self::assertGreaterThanOrEqual($beforeLogin->getTimestamp(), $stamp->getTimestamp());
    }

    public function testRememberMeCookieIsSetWhenChecked(): void
    {
        // Prime CSRF + session, then POST directly so we control the request bag
        // (submitForm() can drop or rewrite the checkbox value depending on its
        // HTML state, which masks the actual server-side behaviour).
        $crawler = $this->client->request('GET', self::LOGIN_PATH);
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', self::LOGIN_PATH, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
            '_csrf_token' => $csrf,
            '_remember_me' => 'on',
        ]);

        $rememberMe = $this->findRememberMeCookie();
        self::assertNotNull($rememberMe, 'REMEMBERME cookie must be set when the checkbox is ticked.');
        self::assertTrue($rememberMe->isHttpOnly(), 'REMEMBERME cookie must be HttpOnly.');
        self::assertGreaterThan(time() + 86400 * 6, $rememberMe->getExpiresTime(), 'REMEMBERME lifetime must be ~1 week.');
    }

    public function testItRedirectsToFrenchHomeWhenUserHasNoLanguage(): void
    {
        // Default user created in setUp() has $language = null → fallback to fr.
        $this->client->request('GET', self::LOGIN_PATH);

        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
        ]);

        self::assertResponseRedirects('/');
    }

    public function testItRedirectsToUserPreferredLanguageOnLogin(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);
        $user->setLanguage(Language::En);
        $em->flush();

        $this->client->request('GET', self::LOGIN_PATH);

        $this->client->submitForm(self::SUBMIT_BUTTON, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
        ]);

        self::assertResponseRedirects('/en');
    }

    public function testRememberMeCookieIsNotSetWhenUnchecked(): void
    {
        // Same direct POST as the "checked" case but without the `_remember_me`
        // field — this is what the browser sends when the user unticks the box.
        $crawler = $this->client->request('GET', self::LOGIN_PATH);
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', self::LOGIN_PATH, [
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
            '_csrf_token' => $csrf,
        ]);

        self::assertNull($this->findRememberMeCookie(), 'REMEMBERME cookie must NOT be set when unchecked.');
    }

    /**
     * Returns the REMEMBERME cookie that *creates* the session (non-empty value),
     * not the preventive clear-cookie Symfony also emits on every login response.
     */
    private function findRememberMeCookie(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('REMEMBERME' === $cookie->getName() && null !== $cookie->getValue() && '' !== $cookie->getValue()) {
                return $cookie;
            }
        }

        return null;
    }
}
