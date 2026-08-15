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
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();

        $user = (new User())
            ->setEmail(self::TEST_EMAIL)
            ->setFirstName('Reset')
            ->setLastName('Tester')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true)
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

    public function testConsumingAValidTokenChangesThePasswordAndAllowsLogin(): void
    {
        $token = $this->generateToken();

        // The token URL stores the token in session and drops it from the URL.
        $this->client->request('GET', self::REQUEST_PATH.'/reset/'.$token);
        self::assertResponseRedirects(self::REQUEST_PATH.'/reset');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Reset password', [
            'change_password_form[plainPassword][first]' => 'brand-new-password-42',
            'change_password_form[plainPassword][second]' => 'brand-new-password-42',
        ]);
        self::assertResponseRedirects('/en/login');

        // Single use: the token row is gone once consumed.
        self::assertCount(0, $this->em->getRepository(ResetPasswordRequest::class)->findAll());

        // The new password actually logs the user in.
        $this->client->request('GET', '/en/login');
        $csrf = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/en/login', [
            '_username' => self::TEST_EMAIL,
            '_password' => 'brand-new-password-42',
            '_csrf_token' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertNotSame('/en/login', $this->client->getResponse()->headers->get('Location'), 'Login with the new password must succeed.');
    }

    public function testAForgedTokenIsRejectedWithoutRevealingAnything(): void
    {
        // Same length as a real token, but never issued.
        $this->client->request('GET', self::REQUEST_PATH.'/reset/'.str_repeat('a', 40));
        $this->client->followRedirect();

        // Generic bounce to the request form with a translated flash: no
        // user lookup result, no distinct status code.
        self::assertResponseRedirects(self::REQUEST_PATH);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString(self::TEST_EMAIL, (string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('form input[name="reset_password_request_form[email]"]')->count());
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->generateToken();

        // Age the request row past its lifetime, as time would.
        $this->em->createQuery('UPDATE '.ResetPasswordRequest::class." r SET r.expiresAt = :past")
            ->setParameter('past', new \DateTimeImmutable('-1 hour'))
            ->execute();
        $this->em->clear();

        $this->client->request('GET', self::REQUEST_PATH.'/reset/'.$token);
        $this->client->followRedirect();

        self::assertResponseRedirects(self::REQUEST_PATH);
    }

    public function testTheResetFormRejectsAnInvalidCsrfToken(): void
    {
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        $passwordBefore = $user->getPassword();
        $token = $this->generateToken();

        $this->client->request('GET', self::REQUEST_PATH.'/reset/'.$token);
        $this->client->followRedirect();

        $this->client->request('POST', self::REQUEST_PATH.'/reset', [
            'change_password_form' => [
                'plainPassword' => ['first' => 'brand-new-password-42', 'second' => 'brand-new-password-42'],
                '_token' => 'forged-token',
            ],
        ]);

        // The form re-renders with the CSRF violation (422 Unprocessable,
        // like any invalid submission); nothing was changed and the token
        // row is still there (not consumed).
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        $this->em->clear();
        self::assertSame($passwordBefore, $this->userRepository->findOneBy(['email' => self::TEST_EMAIL])->getPassword());
        self::assertCount(1, $this->em->getRepository(ResetPasswordRequest::class)->findAll());
    }

    public function testResetRequestsAreRateLimitedPerIp(): void
    {
        // The test env raises the limit to 1000: exhaust it through the same
        // limiter the controller consumes instead of replaying 1000 POSTs.
        /** @var \Symfony\Component\RateLimiter\RateLimiterFactory $factory */
        $factory = static::getContainer()->get('limiter.reset_password_request');
        $limiter = $factory->create('127.0.0.1');
        // Drain whatever budget is left (earlier tests in the class may have
        // legitimately consumed a few tokens; consume(N) with N above the
        // remainder would consume nothing at all).
        for ($i = 0; $i < 1001 && $limiter->consume()->isAccepted(); ++$i) {
        }

        try {
            $this->client->request('GET', self::REQUEST_PATH);
            $this->client->submitForm(self::SUBMIT_BUTTON, [
                'reset_password_request_form[email]' => self::TEST_EMAIL,
            ]);

            self::assertResponseStatusCodeSame(429);
            self::assertEmailCount(0);
        } finally {
            // Never leak an exhausted counter into the shared test cache.
            $limiter->reset();
        }
    }

    private function generateToken(): string
    {
        /** @var \SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface $helper */
        $helper = static::getContainer()->get(\SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface::class);
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);

        return $helper->generateResetToken($user)->getToken();
    }
}
