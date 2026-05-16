<?php

namespace App\Tests\Auth;

use App\Auth\Entity\EmailVerificationRequest;
use App\Auth\Entity\User;
use App\Auth\Repository\EmailVerificationRequestRepository;
use App\Auth\Repository\UserRepository;
use App\Auth\Service\EmailVerificationCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the OTP email-verification flow that replaces the
 * SymfonyCasts signed-link bundle. Covers happy path (correct code → auto-login),
 * wrong code (422 + isVerified stays false), expired code, resend with CSRF
 * protection, and the redirect-away guards on the entry routes.
 *
 * The `register_check_email` session key (set by RegisterController on flow
 * completion) is what tells the controller which user is awaiting verification.
 */
final class EmailVerificationControllerTest extends WebTestCase
{
    private const VERIFY_PATH = '/fr/inscription/verification';
    private const RESEND_PATH = '/fr/inscription/verification/renvoyer';
    private const TEST_EMAIL = 'otp-user@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;
    private EmailVerificationCodeService $codeService;
    private EmailVerificationRequestRepository $requestRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $em = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->userRepository = $container->get(UserRepository::class);
        $this->codeService = $container->get(EmailVerificationCodeService::class);
        $this->requestRepository = $container->get(EmailVerificationRequestRepository::class);

        $this->em->createQuery('DELETE FROM '.EmailVerificationRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testGetRedirectsToRegisterWhenNoPendingEmail(): void
    {
        $this->client->request('GET', self::VERIFY_PATH);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/inscription', $location);
        self::assertStringNotContainsString('/verification', $location);
    }

    public function testGetRendersFormWithSixDigitInputs(): void
    {
        $this->seedPendingUserWithCode();

        $this->client->request('GET', self::VERIFY_PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[data-otp-input-target="hidden"]');
        self::assertCount(6, $this->client->getCrawler()->filter('input[data-otp-input-target="digit"]'));
    }

    public function testCorrectCodeFlipsVerifiedClearsCodeAndLogsTheUserIn(): void
    {
        $code = $this->seedPendingUserWithCode();

        $this->postCode($code);

        // Auto-login: redirect off the verification page and the kernel browser
        // now carries an authenticated session.
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/inscription/verification', $location);

        $this->em->clear();
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);
        self::assertTrue($user->isVerified());
        self::assertNull($this->requestRepository->findOneForUser($user), 'Verification request must be removed on success.');
    }

    public function testWrongCodeReturns422AndKeepsUserUnverified(): void
    {
        $this->seedPendingUserWithCode();

        $this->postCode('000000');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.text-red-600', 'Code');

        $this->em->clear();
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());
    }

    public function testExpiredCodeIsRejected(): void
    {
        $code = $this->seedPendingUserWithCode();

        // Force the expiration into the past so the correct digits still fail.
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);
        $this->em->createQuery(
            'UPDATE '.EmailVerificationRequest::class.' r SET r.expiresAt = :past WHERE r.user = :user'
        )
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->setParameter('user', $user)
            ->execute();
        $this->em->clear();

        $this->postCode($code);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertFalse($user->isVerified());
    }

    public function testResendIssuesAFreshCodeAndSendsAnotherEmail(): void
    {
        $this->seedPendingUserWithCode();
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        $previousHash = $this->requestRepository->findOneForUser($user)->getCodeHash();

        // Read CSRF from the rendered form.
        $this->client->request('GET', self::VERIFY_PATH);
        self::assertResponseIsSuccessful('GET must render the verify form (session must carry the pending email).');
        $token = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token, 'Resend CSRF token must be present in the form.');

        $this->client->request('POST', self::RESEND_PATH, ['_csrf_token' => $token]);

        $status = $this->client->getResponse()->getStatusCode();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(302, $status, sprintf('Resend should redirect (got %d, location=%s).', $status, $location));
        self::assertStringContainsString('/inscription/verification', $location);

        $this->em->clear();
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);
        $newHash = $this->requestRepository->findOneForUser($user)?->getCodeHash();
        self::assertNotNull($newHash);
        self::assertNotSame($previousHash, $newHash, 'Resend must generate a brand-new code.');
        self::assertGreaterThanOrEqual(2, \count($this->getMailerMessages()), 'Resend must dispatch a new email.');
    }

    public function testResendRejectsMissingCsrfToken(): void
    {
        $this->seedPendingUserWithCode();

        $this->client->request('POST', self::RESEND_PATH, ['_csrf_token' => 'bogus']);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Creates an unverified user, generates+sends the OTP, stashes the email in
     * the session under `register_check_email` (the contract the controller uses
     * to find the pending user), and returns the plaintext 6-digit code.
     */
    private function seedPendingUserWithCode(): string
    {
        $user = (new User())
            ->setEmail(self::TEST_EMAIL)
            ->setFirstName('Otp')
            ->setLastName('Tester')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setPassword('placeholder');
        $this->em->persist($user);
        $this->em->flush();

        $code = $this->codeService->generateAndSend($user);

        // Build a session out-of-band (no active request yet) via session.factory
        // and attach its id to the test client cookie jar so the next ->request()
        // hydrates the controller's session with our prepared key.
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('register_check_email', self::TEST_EMAIL);
        $session->save();
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()),
        );

        return $code;
    }

    private function postCode(string $code): void
    {
        $this->client->request('GET', self::VERIFY_PATH);
        $csrf = $this->client->getCrawler()->filter('input[name="verification_code_form[_token]"]')->attr('value');

        $this->client->request('POST', self::VERIFY_PATH, [
            'verification_code_form' => [
                'code' => $code,
                '_token' => $csrf,
            ],
        ]);
    }
}
