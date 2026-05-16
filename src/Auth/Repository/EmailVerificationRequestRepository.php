<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\EmailVerificationRequest;
use App\Auth\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailVerificationRequest>
 */
class EmailVerificationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationRequest::class);
    }

    public function findOneForUser(User $user): ?EmailVerificationRequest
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(EmailVerificationRequest $request, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($request);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(EmailVerificationRequest $request, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($request);
        if ($flush) {
            $em->flush();
        }
    }

    public function removeForUser(User $user, bool $flush = true): void
    {
        $existing = $this->findOneForUser($user);
        if (null !== $existing) {
            $this->remove($existing, $flush);
        }
    }

    /**
     * Cron-friendly cleanup: drops every request whose expiration is in the past.
     * Returns the number of rows deleted.
     */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM '.EmailVerificationRequest::class.' r WHERE r.expiresAt < :now')
            ->setParameter('now', $now)
            ->execute();
    }
}
