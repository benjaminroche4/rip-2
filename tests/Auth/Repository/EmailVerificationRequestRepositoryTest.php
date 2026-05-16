<?php

namespace App\Tests\Auth\Repository;

use App\Auth\Entity\EmailVerificationRequest;
use App\Auth\Entity\User;
use App\Auth\Repository\EmailVerificationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Repository layer for the dedicated email_verification_request table. Covers:
 *  - one active request per user can be looked up,
 *  - removeForUser is a no-op when nothing pending — never throws,
 *  - purgeExpired only deletes rows whose expiresAt is strictly in the past.
 */
final class EmailVerificationRequestRepositoryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private EmailVerificationRequestRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->repository = $container->get(EmailVerificationRequestRepository::class);

        $this->em->createQuery('DELETE FROM '.EmailVerificationRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testFindOneForUserReturnsTheActiveRequestOrNull(): void
    {
        $user = $this->persistUser('a@example.com');

        self::assertNull($this->repository->findOneForUser($user));

        $request = new EmailVerificationRequest($user, 'hash', new \DateTimeImmutable('+15 minutes'));
        $this->repository->save($request);

        $found = $this->repository->findOneForUser($user);
        self::assertNotNull($found);
        self::assertSame($request->getId(), $found->getId());
    }

    public function testRemoveForUserIsNoopWhenNothingPending(): void
    {
        $user = $this->persistUser('noop@example.com');

        $this->repository->removeForUser($user); // must not throw

        self::assertNull($this->repository->findOneForUser($user));
    }

    public function testPurgeExpiredDeletesOnlyPastRows(): void
    {
        $expiredUser = $this->persistUser('past@example.com');
        $futureUser = $this->persistUser('future@example.com');

        $this->repository->save(new EmailVerificationRequest($expiredUser, 'h1', new \DateTimeImmutable('-1 minute')));
        $this->repository->save(new EmailVerificationRequest($futureUser, 'h2', new \DateTimeImmutable('+15 minutes')));

        $deleted = $this->repository->purgeExpired(new \DateTimeImmutable());

        self::assertSame(1, $deleted);
        self::assertNull($this->repository->findOneForUser($expiredUser));
        self::assertNotNull($this->repository->findOneForUser($futureUser));
    }

    private function persistUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Repo')
            ->setLastName('Tester')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
