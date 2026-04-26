<?php

namespace App\Tests;

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
}
