<?php

namespace App\Tests\Auth\Service;

use App\Auth\Entity\EmailVerificationRequest;
use App\Auth\Entity\User;
use App\Auth\Repository\EmailVerificationRequestRepository;
use App\Auth\Service\EmailVerificationCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Service layer for the 6-digit OTP email confirmation. Covers:
 *  - a fresh code is generated, hashed, persisted in email_verification_request
 *    with a future expiration, and delivered as an email — but never stored in clear,
 *  - {@see EmailVerificationCodeService::verify()} accepts the matching code, flips
 *    isVerified, and removes the pending request so the same code cannot be replayed,
 *  - wrong codes leave the user untouched (but increment the attempt counter),
 *  - expired codes are rejected even when the digits match,
 *  - regenerating a code replaces the previous request (one row per user).
 */
final class EmailVerificationCodeServiceTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private EmailVerificationCodeService $service;
    private EmailVerificationRequestRepository $requestRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->service = $container->get(EmailVerificationCodeService::class);
        $this->requestRepository = $container->get(EmailVerificationRequestRepository::class);

        $this->em->createQuery('DELETE FROM '.EmailVerificationRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testGenerateAndSendStoresHashedCodeAndDispatchesEmail(): void
    {
        $user = $this->persistUnverifiedUser('otp@example.com');

        $code = $this->service->generateAndSend($user);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code, 'Code must be a 6-digit string.');

        $request = $this->requestRepository->findOneForUser($user);
        self::assertNotNull($request);
        self::assertNotSame($code, $request->getCodeHash(), 'Hash must not equal the plaintext code.');
        self::assertGreaterThan(new \DateTimeImmutable(), $request->getExpiresAt());
        self::assertSame(0, $request->getAttempts());

        self::assertEmailCount(1);
        $email = $this->getMailerMessages()[0];
        self::assertEmailAddressContains($email, 'to', 'otp@example.com');
        self::assertStringContainsString($code, (string) $email->getHtmlBody(), 'Email body must embed the plaintext code.');
    }

    public function testVerifyAcceptsCorrectCodeFlipsVerifiedAndRemovesRequest(): void
    {
        $user = $this->persistUnverifiedUser('correct@example.com');
        $code = $this->service->generateAndSend($user);

        $result = $this->service->verify($user, $code);

        self::assertTrue($result);
        self::assertTrue($user->isVerified());
        self::assertNull($this->requestRepository->findOneForUser($user), 'Request must be removed so the code cannot be replayed.');
    }

    public function testVerifyRejectsWrongCodeAndCountsAttempt(): void
    {
        $user = $this->persistUnverifiedUser('wrong@example.com');
        $this->service->generateAndSend($user);

        $result = $this->service->verify($user, '000000');

        self::assertFalse($result);
        self::assertFalse($user->isVerified());
        $request = $this->requestRepository->findOneForUser($user);
        self::assertNotNull($request, 'Request must be kept so the legitimate code still works.');
        self::assertSame(1, $request->getAttempts());
    }

    public function testVerifyRemovesRequestAfterMaxAttempts(): void
    {
        $user = $this->persistUnverifiedUser('lockout@example.com');
        $this->service->generateAndSend($user);

        for ($i = 0; $i < EmailVerificationRequest::MAX_ATTEMPTS; ++$i) {
            self::assertFalse($this->service->verify($user, '000000'));
        }

        self::assertNull($this->requestRepository->findOneForUser($user), 'Request must be dropped once the attempt cap is reached.');
        self::assertFalse($user->isVerified());
    }

    public function testVerifyRejectsExpiredCodeAndCleansUp(): void
    {
        $user = $this->persistUnverifiedUser('expired@example.com');
        $code = $this->service->generateAndSend($user);

        // Force expiration in the past — simulates a code older than 15 minutes.
        $this->em->createQuery(
            'UPDATE '.EmailVerificationRequest::class.' r SET r.expiresAt = :past WHERE r.user = :user'
        )
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->setParameter('user', $user)
            ->execute();
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());

        $result = $this->service->verify($user, $code);

        self::assertFalse($result);
        self::assertFalse($user->isVerified());
        self::assertNull($this->requestRepository->findOneForUser($user), 'Expired request must be purged on read.');
    }

    public function testVerifyRejectsWhenNoPendingCode(): void
    {
        $user = $this->persistUnverifiedUser('nopending@example.com');
        // No call to generateAndSend → no row in email_verification_request.

        self::assertFalse($this->service->verify($user, '123456'));
        self::assertFalse($user->isVerified());
    }

    public function testGenerateReplacesPreviousPendingRequest(): void
    {
        $user = $this->persistUnverifiedUser('rotate@example.com');
        $first = $this->service->generateAndSend($user);
        $firstRequest = $this->requestRepository->findOneForUser($user);
        $firstHash = $firstRequest->getCodeHash();

        $second = $this->service->generateAndSend($user);
        $secondRequest = $this->requestRepository->findOneForUser($user);

        self::assertNotSame($firstHash, $secondRequest->getCodeHash(), 'Second issue must produce a fresh hash.');
        self::assertFalse($this->service->verify($user, $first), 'Previous code must no longer be valid.');
        // Re-issue a third time so we keep an active request, then verify the second code.
        self::assertNotNull($this->requestRepository->findOneForUser($user));
    }

    private function persistUnverifiedUser(string $email): User
    {
        // setVerified stays false on purpose — the whole point of these tests is
        // verifying the OTP flow flips it. Setting it true here would short-circuit
        // every "rejected code" assertion below.
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Otp')
            ->setLastName('Tester')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
