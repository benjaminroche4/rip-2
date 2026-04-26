<?php

namespace App\Tests;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the symfonycasts/reset-password-bundle flow end-to-end on the
 * English route (deterministic translation for assertions).
 *
 * Asserts the bundle still:
 *  - renders the request form on GET
 *  - dispatches one email when a known address is submitted
 *  - persists a ResetPasswordRequest row tying the token to the user
 *
 * The token-validation + new-password steps are exercised implicitly
 * through the bundle and don't need a brittle full-flow walkthrough —
 * the unit being protected here is "did we wire the bundle up correctly,
 * and does it survive an entity migration?"
 */
final class ResetPasswordControllerTest extends WebTestCase
{
    private const REQUEST_PATH = '/en/reset-password';
    private const SUBMIT_BUTTON = 'Send reset link';
    private const TEST_EMAIL = 'reset-test@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $this->userRepository = $container->get(UserRepository::class);

        // Cascade-friendly cleanup: child rows first, then users.
        $this->em->createQuery('DELETE FROM ' . ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();

        $user = (new User())
            ->setEmail(self::TEST_EMAIL)
            ->setFirstName('Reset')
            ->setLastName('Tester')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setPassword('placeholder-not-used-in-this-test');

        $this->em->persist($user);
        $this->em->flush();
    }

    public function testRequestFormRenders(): void
    {
        $this->client->request('GET', self::REQUEST_PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="reset_password_request_form[email]"]');
    }

    public function testSubmittingEmailDispatchesOneEmailAndPersistsToken(): void
    {
        $this->client->request('GET', self::REQUEST_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            'reset_password_request_form[email]' => self::TEST_EMAIL,
        ]);

        // The bundle dispatches one email regardless of the mailer transport;
        // the test profiler captures it via Symfony's mailer message logger.
        self::assertEmailCount(1);
        $message = $this->getMailerMessages()[0];
        self::assertEmailAddressContains($message, 'to', self::TEST_EMAIL);

        // Bundle persisted a token row tied to the user.
        $tokenRows = $this->em->getRepository(ResetPasswordRequest::class)->findAll();
        self::assertCount(1, $tokenRows);
        self::assertSame(self::TEST_EMAIL, $tokenRows[0]->getUser()->getEmail());
    }

    public function testSubmittingUnknownEmailDoesNotLeakUserExistence(): void
    {
        // The bundle should still respond identically (redirect to check-email)
        // for an address with no matching user — that's the security promise.
        $this->client->request('GET', self::REQUEST_PATH);
        $this->client->submitForm(self::SUBMIT_BUTTON, [
            'reset_password_request_form[email]' => 'nobody@example.com',
        ]);

        // Whether the bundle sends an email or not for unknown addresses is a
        // configuration concern; what we assert here is that the response is a
        // redirect (i.e. the form was accepted), so we don't reveal the absence
        // of the user via a different status code.
        self::assertResponseStatusCodeSame(302);
    }
}
